<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Webkernel\StdGit\Processors\CommandBuilder;
use Webkernel\XMonorepo\Engine\Discovery\PackageDefinition;
use Webkernel\XMonorepo\Exceptions\SplitException;
use Webkernel\XMonorepo\XMonorepo;

final class SplitCommand extends Command
{
    private \DateTime $startTime;

    public function __construct(private readonly XMonorepo $xMonorepo)
    {
        parent::__construct('split');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Split eligible packages and push them to their split repositories.')
            ->addOption('tag',     't', InputOption::VALUE_REQUIRED, 'Version tag to apply.')
            ->addOption('package', 'p', InputOption::VALUE_REQUIRED, 'Limit to a single package name.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE,    'Discover and plan without pushing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->startTime = new \DateTime();

        $tag           = (string) ($input->getOption('tag') ?? $this->xMonorepo->getConfig()->getString('tag', ''));
        $filterPackage = $input->getOption('package');
        $dryRun        = (bool) $input->getOption('dry-run');

        // ── Discovery ────────────────────────────────────────────────────────
        $packages = $this->xMonorepo->createDiscovery()->discover();

        if ($filterPackage !== null) {
            $packages = array_values(array_filter(
                $packages, static fn ($p) => $p->getName() === $filterPackage
            ));
            if ($packages === []) {
                $output->writeln("<error>Package '$filterPackage' not found.</error>");
                return Command::FAILURE;
            }
        }

        if ($packages === []) {
            $output->writeln('No eligible packages found.');
            return Command::SUCCESS;
        }

        // ── Tag prompt ───────────────────────────────────────────────────────
        if ($tag === '' && $input->isInteractive() && !$dryRun) {
            $answer = $this->getHelper('question')->ask(
                $input, $output,
                new Question('Tag to create and push? <comment>[empty = no tag]</comment> ', '')
            );
            $tag = is_string($answer) ? trim($answer) : '';
        }

        // ── Header ───────────────────────────────────────────────────────────
        $output->writeln(['', ' <bg=blue;fg=white> WEBKERNEL MONOREPO SPLIT ENGINE </>', ' <fg=gray>==================================</>', '']);
        $this->renderSummary($output, $packages, $tag, $dryRun);

        // ── Confirmation ─────────────────────────────────────────────────────
        if (!$dryRun && $input->isInteractive()) {
            $confirmed = $this->getHelper('question')->ask(
                $input, $output,
                new ConfirmationQuestion(
                    sprintf("  <question>Ready to split %d packages? [y/N]</question> ", count($packages)),
                    false
                )
            );
            if (!$confirmed) {
                $output->writeln("\n  <comment>Aborted by user.</comment>");
                return Command::SUCCESS;
            }
        }

        $output->writeln('');
        $engine = $this->xMonorepo->createSplitEngine();
        $total  = count($packages);
        $failed = [];

        // ════════════════════════════════════════════════════════════════════
        // PHASE 1/3 — Prepare
        // ════════════════════════════════════════════════════════════════════
        $output->writeln(sprintf(
            ' [%s] <bg=cyan;fg=black> PHASE 1/3 </> Preparing versions and commits...',
            $this->ts()
        ));

        $skipped = [];

        foreach ($packages as $i => $package) {
            $output->write(sprintf(
                '  [%s] <info>PREP</info>  %-40s [%d/%d] ',
                $this->ts(), $package->getName(), $i + 1, $total
            ));

            if ($dryRun) {
                $output->writeln('<fg=gray>skipped</>');
                continue;
            }

            try {
                $entry = $engine->prepare($package, $tag);
                if ($entry->getStatus()->value === 'completed') {
                    $output->writeln('<fg=gray>up-to-date</>');
                    $skipped[$package->getName()] = true;
                } else {
                    $output->writeln('<info>OK</info>');
                }
            } catch (SplitException $e) {
                $output->writeln('<error>FAILED</error>');
                $output->writeln(sprintf('         <fg=red>%s</>', $e->getMessage()));
                $failed[$package->getName()] = true;
            }
        }

        $activePackages = $this->excludeKeys($packages, $failed + $skipped);

        // ════════════════════════════════════════════════════════════════════
        // PHASE 2/3 — Push
        // ════════════════════════════════════════════════════════════════════
        $output->writeln("\n" . sprintf(
            ' [%s] <bg=cyan;fg=black> PHASE 2/3 </> Pushing to remotes...',
            $this->ts()
        ));

        $pushed = [];

        foreach ($activePackages as $i => $package) {
            $output->writeln(sprintf(
                '  [%s] <info>PUSH</info>  %-40s [%d/%d]',
                $this->ts(), $package->getName(), $i + 1, count($activePackages)
            ));
            $output->writeln(sprintf('       <fg=blue>-> %s</>', $package->getSplitRepoUrl()));

            if ($dryRun) {
                $output->writeln('       <fg=gray>skipped</>');
                $pushed[] = $package;
                continue;
            }

            try {
                $engine->push(
                    $package,
                    $tag,
                    static function (string $type, string $chunk) use ($output): void {
                        foreach (explode("\n", trim(CommandBuilder::maskCredentials($chunk))) as $line) {
                            if ($line !== '') {
                                $output->writeln(sprintf('          <fg=gray>%s</>', $line));
                            }
                        }
                    }
                );
                $output->writeln('       <info>OK</info>');
                $pushed[] = $package;
            } catch (SplitException $e) {
                $output->writeln(sprintf('       <error>FAILED: %s</error>', $e->getMessage()));
                $failed[$package->getName()] = true;
            }
        }

        $activePackages = $this->excludeKeys($activePackages, $failed);

        // ════════════════════════════════════════════════════════════════════
        // PHASE 3/3 — Tag
        // ════════════════════════════════════════════════════════════════════
        $output->writeln("\n" . sprintf(
            ' [%s] <bg=cyan;fg=black> PHASE 3/3 </> Tagging releases...',
            $this->ts()
        ));

        foreach ($activePackages as $i => $package) {
            $output->write(sprintf(
                '  [%s] <info>TAG</info>   %-40s [%d/%d] <fg=gray>(%s)</> ',
                $this->ts(), $package->getName(), $i + 1, count($activePackages),
                $tag !== '' ? $tag : 'no tag'
            ));

            if ($dryRun || $tag === '') {
                $output->writeln('<fg=gray>' . ($dryRun ? 'skipped' : 'skipped (no tag)') . '</>');
                if (!$dryRun) {
                    $engine->markCompleted($package, $tag);
                }
                continue;
            }

            try {
                $engine->tag($package, $tag);
                $output->writeln('<info>OK</info>');
            } catch (SplitException $e) {
                $output->writeln('<error>FAILED</error>');
                $output->writeln(sprintf('         <fg=red>%s</>', $e->getMessage()));
                $failed[$package->getName()] = true;
            }
        }

        // ── Cleanup (always, even on partial failure) ─────────────────────
        if (!$dryRun) {
            foreach ($pushed as $package) {
                $engine->cleanupPackageRepository($package);
            }
            if ($pushed !== []) {
                $output->writeln("\n  <fg=gray>Cleaned package .git directories.</>");
            }
        }

        // ── Footer ───────────────────────────────────────────────────────────
        $failCount    = count($failed);
        $skippedCount = count($skipped);

        $output->writeln([
            '',
            $failCount === 0
                ? sprintf(
                    ' [%s] <bg=green;fg=black> SUCCESS </> %d pushed%s%s in %s.',
                    $this->ts(),
                    count($pushed),
                    $tag !== '' ? ", tagged as {$tag}" : '',
                    $skippedCount > 0 ? ", {$skippedCount} already up-to-date" : '',
                    $this->elapsed()
                )
                : sprintf(
                    ' [%s] <bg=red;fg=white> PARTIAL </> %d of %d packages failed. Elapsed: %s.',
                    $this->ts(), $failCount, $total, $this->elapsed()
                ),
            '',
        ]);

        return $failCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @param PackageDefinition[] $packages */
    private function renderSummary(OutputInterface $output, array $packages, string $tag, bool $dryRun): void
    {
        $repo = $this->xMonorepo->getGit()->open($this->xMonorepo->getMonorepoRoot());
        $head = substr($repo->getLastCommitId()->toString(), 0, 12);

        $output->writeln(sprintf('  <info>HEAD</info>     %s (%d commits)', $head, $repo->getCommitCount()));
        $output->writeln(sprintf('  <info>TAG</info>      %s', $tag !== '' ? $tag : '<fg=gray>none (snapshot mode)</>'));
        $output->writeln(sprintf('  <info>MODE</info>     %s', $dryRun ? '<comment>dry-run</comment>' : 'push'));
        $output->writeln(sprintf('  <info>COUNT</info>    %d packages', count($packages)));
        $output->writeln('');

        foreach ($packages as $package) {
            $output->writeln(sprintf(
                '  - %-38s -> %s <fg=gray>(%s)</>',
                $package->getName(),
                $package->getSplitRepoUrl(),
                $package->getDefaultBranch()
            ));
        }

        $output->writeln('');
    }

    /**
     * @param PackageDefinition[]  $packages
     * @param array<string, true>  $keys
     * @return PackageDefinition[]
     */
    private function excludeKeys(array $packages, array $keys): array
    {
        return array_values(array_filter(
            $packages,
            static fn ($p) => !isset($keys[$p->getName()])
        ));
    }

    private function ts(): string
    {
        return (new \DateTime())->format('H:i:s');
    }

    private function elapsed(): string
    {
        $diff = (new \DateTime())->diff($this->startTime);
        return sprintf('%02d:%02d:%02d', $diff->h, $diff->i, $diff->s);
    }
}
