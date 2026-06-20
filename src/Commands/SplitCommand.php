<?php declare(strict_types=1);
namespace Webkernel\XMonorepo\Commands;

use Composer\Semver\VersionParser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Webkernel\StdGit\Processors\CommandBuilder;
use Webkernel\XMonorepo\Engine\Discovery\PackageDefinition;
use Webkernel\XMonorepo\Engine\SplitEngine;
use Webkernel\XMonorepo\Exceptions\SplitException;
use Webkernel\XMonorepo\XMonorepo;

final class SplitCommand extends Command
{
    private \DateTime $startTime;
    private readonly VersionParser $parser;

    public function __construct(private readonly XMonorepo $xMonorepo)
    {
        $this->parser = new VersionParser();
        parent::__construct('split');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Split eligible packages and push them to their split repositories.')
            ->addOption('tag',       't', InputOption::VALUE_REQUIRED, 'Version tag to apply.')
            ->addOption('package',   'p', InputOption::VALUE_REQUIRED, 'Limit to a single package name.')
            ->addOption('changelog', null, InputOption::VALUE_NONE, 'Write package changelogs.')
            ->addOption('dry-run',   null, InputOption::VALUE_NONE, 'Discover and validate without pushing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->startTime = new \DateTime();

        $output->writeln('
 ██╗  ██╗███╗   ███╗ ██████╗ ███╗   ██╗ ██████╗ ██████╗ ███████╗██████╗  ██████╗
 ╚██╗██╔╝████╗ ████║██╔═══██╗████╗  ██║██╔═══██╗██╔══██╗██╔════╝██╔══██╗██╔═══██╗
  ╚███╔╝ ██╔████╔██║██║   ██║██╔██╗ ██║██║   ██║██████╔╝█████╗  ██████╔╝██║   ██║
  ██╔██╗ ██║╚██╔╝██║██║   ██║██║╚██╗██║██║   ██║██╔══██╗██╔══╝  ██╔═══╝ ██║   ██║
 ██╔╝ ██╗██║ ╚═╝ ██║╚██████╔╝██║ ╚████║╚██████╔╝██║  ██║███████╗██║     ╚██████╔╝
 ╚═╝  ╚═╝╚═╝     ╚═╝ ╚═════╝ ╚═╝  ╚═══╝ ╚═════╝ ╚═╝  ╚═╝╚══════╝╚═╝      ╚═════╝
 <comment>Split, sync, and release a monorepo directly from the codebase.</comment>');
        $output->writeln(sprintf(' <info>[%s]</info> Checking current state...', $this->ts()));
        $output->writeln('');

        $tag = (string) ($input->getOption('tag') ?? $this->xMonorepo->getConfig()->getString('tag', ''));
        $filterPackage = $input->getOption('package');
        $dryRun = (bool) $input->getOption('dry-run');
        $changelogConfig = $this->xMonorepo->getConfig()->getArray('changelog');
        $writeChangelog = (bool) ($changelogConfig['enabled'] ?? false) || (bool) $input->getOption('changelog');

        $packages = $this->discoverPackages($filterPackage, $output);
        if ($packages === []) {
            return Command::SUCCESS;
        }

        $engine = $this->xMonorepo->createSplitEngine();
        register_shutdown_function(function () use ($engine, $packages): void {
            foreach ($packages as $package) {
                $engine->cleanupPackageRepository($package);
            }
        });

        if (!$dryRun && !$this->handleMonorepoSync($input, $output)) {
            return Command::FAILURE;
        }

        $packages = $this->filterMissingRemotes($input, $output, $engine, $packages, $dryRun);
        if ($packages === []) {
            $output->writeln('  <comment>No package left to split.</comment>');
            return Command::SUCCESS;
        }

        if ($tag === '' && $input->isInteractive() && !$dryRun) {
            $questionHelper = $this->getHelper('question');

            while (true) {
                $answer = $questionHelper->ask(
                    $input,
                    $output,
                    new Question('Tag to create and push? <comment>[empty = no tag]</comment> ', '')
                );

                $tag = is_string($answer) ? trim($answer) : '';

                if ($tag === '') {
                    break;
                }

                try {
                    $this->parser->normalize($tag);
                    break;
                } catch (\UnexpectedValueException) {
                    $output->writeln('<error>Invalid SemVer format. Please try again (e.g., 1.0.0, v2.1.0-beta).</error>');
                }
            }
        }

        $output->writeln(['', ' <bg=blue;fg=white> WEBKERNEL MONOREPO SPLIT ENGINE </>', ' <fg=gray>==================================</>', '']);
        $this->renderSummary($output, $packages, $tag, $dryRun, $writeChangelog);

        if (!$dryRun && $input->isInteractive()) {
            $confirmed = $this->getHelper('question')->ask(
                $input,
                $output,
                new ConfirmationQuestion(sprintf("  <question>Split %d packages? [y/N]</question> ", count($packages)), false)
            );

            if (!$confirmed) {
                $output->writeln("\n  <comment>Aborted by user.</comment>");
                return Command::SUCCESS;
            }
        }

        $failed = [];
        $pushed = 0;

        $output->writeln("\n" . sprintf(
            ' [%s] <bg=cyan;fg=black> SPLIT </> Cloning remotes, syncing files, pushing normally...',
            $this->ts()
        ));

        foreach ($packages as $i => $package) {
            $output->writeln(sprintf(
                '  [%s] <info>SYNC</info>  %-40s [%d/%d]',
                $this->ts(),
                $package->getName(),
                $i + 1,
                count($packages)
            ));
            $output->writeln(sprintf('       <fg=blue>-> %s</>', $package->getSplitRepoUrl()));

            if ($dryRun) {
                $output->writeln('       <fg=gray>skipped</>');
                continue;
            }

            try {
                $entry = $engine->split($package, $tag, $writeChangelog, $this->gitOutput($output));
                $output->writeln($entry->getStatus()->value === 'completed' ? '       <info>OK</info>' : '       <fg=gray>skipped</>');
                $pushed++;
            } catch (SplitException $e) {
                $output->writeln(sprintf('       <error>FAILED: %s</error>', $e->getMessage()));
                $failed[$package->getName()] = true;
            }
        }

        $failCount = count($failed);

        $output->writeln([
            '',
            $failCount === 0
                ? sprintf(' [%s] <bg=green;fg=black> SUCCESS </> %d package(s) processed in %s.', $this->ts(), $pushed, $this->elapsed())
                : sprintf(' [%s] <bg=red;fg=white> PARTIAL </> %d of %d packages failed. Elapsed: %s.', $this->ts(), $failCount, count($packages), $this->elapsed()),
            '',
        ]);

        return $failCount === 0 ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return PackageDefinition[]
     */
    private function discoverPackages(mixed $filterPackage, OutputInterface $output): array
    {
        $packages = $this->xMonorepo->createDiscovery()->discover();

        if ($filterPackage !== null) {
            $packages = array_values(array_filter(
                $packages,
                static fn (PackageDefinition $package): bool => $package->getName() === $filterPackage
            ));

            if ($packages === []) {
                $output->writeln("<error>Package '$filterPackage' not found.</error>");
            }
        }

        if ($packages === []) {
            $output->writeln('No eligible packages found.');
        }

        return $packages;
    }

    private function handleMonorepoSync(InputInterface $input, OutputInterface $output): bool
    {
        $repo = $this->xMonorepo->getGit()->open($this->xMonorepo->getMonorepoRoot());

        if ($repo->isSyncedWithUpstream()) {
            return true;
        }

        if (!$input->isInteractive()) {
            $output->writeln('<error>Monorepo is not synced. Commit/push it before running split non-interactively.</error>');
            return false;
        }

        if ($repo->hasChanges()) {
            $commit = $this->getHelper('question')->ask(
                $input,
                $output,
                new ConfirmationQuestion('  <question>Monorepo has uncommitted changes. Commit them before split? [Y/n]</question> ', true)
            );

            if (!$commit) {
                return (bool) $this->getHelper('question')->ask(
                    $input,
                    $output,
                    new ConfirmationQuestion('  <question>Continue split with uncommitted monorepo changes? [y/N]</question> ', false)
                );
            }

            $message = $this->getHelper('question')->ask(
                $input,
                $output,
                new Question('  Commit message <comment>[x-monorepo: pre-split]</comment> ', 'x-monorepo: pre-split')
            );

            $repo->addAllChanges()->commit(is_string($message) && trim($message) !== '' ? trim($message) : 'x-monorepo: pre-split');
        }

        if (!$repo->hasUnpushedCommits()) {
            return true;
        }

        $push = $this->getHelper('question')->ask(
            $input,
            $output,
            new ConfirmationQuestion('  <question>Monorepo has unpushed commits. Push current branch first? [Y/n]</question> ', true)
        );

        if (!$push) {
            return true;
        }

        $output->writeln('  <info>Pushing monorepo current branch...</info>');
        $repo->pushCurrentBranch($this->gitOutput($output));

        return true;
    }

    /**
     * @param  PackageDefinition[] $packages
     * @return PackageDefinition[]
     */
    private function filterMissingRemotes(
        InputInterface $input,
        OutputInterface $output,
        SplitEngine $engine,
        array $packages,
        bool $dryRun
    ): array {
        while (true) {
            $missing = [];

            foreach ($packages as $package) {
                if (!$engine->remoteExists($package)) {
                    $missing[$package->getName()] = $package;
                }
            }

            if ($missing === []) {
                return $packages;
            }

            $output->writeln("\n  <comment>Missing or inaccessible split repositories:</comment>");
            foreach ($missing as $package) {
                $output->writeln(sprintf('  - %-38s -> %s', $package->getName(), $package->getSplitRepoUrl()));
            }

            if ($dryRun || !$input->isInteractive()) {
                return $this->excludeKeys($packages, array_fill_keys(array_keys($missing), true));
            }

            $choice = $this->getHelper('question')->ask(
                $input,
                $output,
                new Question('  <question>Action? [R retry / d do not include]</question> ', 'R')
            );

            if (strtolower((string) $choice) === 'd') {
                return $this->excludeKeys($packages, array_fill_keys(array_keys($missing), true));
            }
        }
    }

    /** @param PackageDefinition[] $packages */
    private function renderSummary(OutputInterface $output, array $packages, string $tag, bool $dryRun, bool $writeChangelog): void
    {
        $repo = $this->xMonorepo->getGit()->open($this->xMonorepo->getMonorepoRoot());
        $head = substr($repo->getLastCommitId()->toString(), 0, 12);

        $output->writeln(sprintf('  <info>HEAD</info>       %s (%d commits)', $head, $repo->getCommitCount()));
        $output->writeln(sprintf('  <info>TAG</info>        %s', $tag !== '' ? $tag : '<fg=gray>none (snapshot mode)</>'));
        $output->writeln(sprintf('  <info>MODE</info>       %s', $dryRun ? '<comment>dry-run</comment>' : 'push'));
        $output->writeln(sprintf('  <info>CHANGELOG</info>  %s', $writeChangelog ? 'yes' : '<fg=gray>no</>'));
        $output->writeln(sprintf('  <info>COUNT</info>      %d packages', count($packages)));
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
     * @param PackageDefinition[] $packages
     * @param array<string, true> $keys
     * @return PackageDefinition[]
     */
    private function excludeKeys(array $packages, array $keys): array
    {
        return array_values(array_filter(
            $packages,
            static fn (PackageDefinition $package): bool => !isset($keys[$package->getName()])
        ));
    }

    private function gitOutput(OutputInterface $output): callable
    {
        return static function (string $type, string $chunk) use ($output): void {
            foreach (explode("\n", trim(CommandBuilder::maskCredentials($chunk))) as $line) {
                if ($line !== '') {
                    $output->writeln(sprintf('          <fg=gray>%s</>', $line));
                }
            }
        };
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
