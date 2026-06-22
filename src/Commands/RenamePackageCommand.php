<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Webkernel\XMonorepo\Engine\Rename\PackageRenameEngine;
use Webkernel\XMonorepo\Engine\Rename\PackageRenamePlan;
use Webkernel\XMonorepo\Engine\Sync\ComposerJsonWriter;
use Webkernel\XMonorepo\Engine\Sync\InternalPackageCatalog;
use Webkernel\XMonorepo\Engine\Sync\InternalPackageRecord;
use Webkernel\XMonorepo\Engine\Sync\PackageModuleClassifier;
use Webkernel\XMonorepo\Exceptions\XMonorepoException;
use Webkernel\XWebdev\XWebdev;

final class RenamePackageCommand extends MonorepoCommand
{
    public function __construct(XWebdev $webdev)
    {
        parent::__construct($webdev, 'rename-package');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Rename a package directory and align composer name, replace, and webkernel metadata.')
            ->setAliases(['monorepo:rename'])
            ->addArgument('from', InputArgument::OPTIONAL, 'Current package path (e.g. component-routing or packages/component-routing).')
            ->addArgument('to', InputArgument::OPTIONAL, 'New package path (e.g. component-config-routing).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned changes without writing files.')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Apply changes without confirmation.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the plan as JSON.');
    }

    #[\Override]
    protected function needsInput(InputInterface $input): bool
    {
        return trim((string) $input->getArgument('from')) === ''
            || trim((string) $input->getArgument('to')) === '';
    }

    #[\Override]
    protected function showIntro(OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln(' <bg=blue;fg=white> WEBKERNEL MONOREPO PACKAGE RENAME </>');
        $output->writeln(' <fg=gray>Rename a package directory and update composer metadata.</fg=gray>');
        $output->writeln('');
    }

    protected function promptMissing(InputInterface $input, OutputInterface $output): void
    {
        $helper   = $this->getHelper('question');
        $packages = $this->listPackages();

        if ($packages === []) {
            throw new XMonorepoException('No packages found under the monorepo packages directory.');
        }

        $this->renderPackageList($output, $packages);

        if (trim((string) $input->getArgument('from')) === '') {
            $choices = [];
            foreach ($packages as $package) {
                $choices[$package->relativePath] = sprintf(
                    '%s [%s]',
                    $package->relativePath,
                    $package->name,
                );
            }

            $answer = $helper->ask(
                $input,
                $output,
                new ChoiceQuestion(' <fg=cyan>Package to rename</>: ', $choices)
            );

            $input->setArgument('from', (string) $answer);
        }

        $from = trim((string) $input->getArgument('from'));

        if (trim((string) $input->getArgument('to')) === '') {
            while (true) {
                $answer = $helper->ask(
                    $input,
                    $output,
                    new Question(' <fg=cyan>New package path</> (e.g. component-config-routing): ')
                );

                $to = is_string($answer) ? trim($answer) : '';

                if ($to === '') {
                    $output->writeln('<error>Path cannot be empty.</error>');
                    continue;
                }

                if ($this->normalizeRelativePath($to) === $this->normalizeRelativePath($from)) {
                    $output->writeln('<error>New path must differ from the current path.</error>');
                    continue;
                }

                try {
                    $this->engine()->plan($from, $to);
                    $input->setArgument('to', $to);
                    break;
                } catch (XMonorepoException $e) {
                    $output->writeln('<error>' . $e->getMessage() . '</error>');
                }
            }
        }

        $output->writeln('');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->isInteractive() && $this->needsInput($input)) {
            return $this->gentleStart($output, [
                'Run without arguments to start the interactive wizard.',
                'Direct rename: <info>monorepo:rename-package component-routing component-config-routing</info>',
            ]);
        }

        if ($this->needsInput($input)) {
            $output->writeln('<error>Both from and to package paths are required.</error>');
            return Command::FAILURE;
        }

        try {
            $engine = $this->engine();
            $plan   = $engine->plan(
                (string) $input->getArgument('from'),
                (string) $input->getArgument('to'),
            );

            if ($input->getOption('json')) {
                return $this->renderJson($output, $plan, (bool) $input->getOption('dry-run'), $engine);
            }

            $this->renderPlan($output, $plan);

            if (!$plan->hasChanges()) {
                $output->writeln('');
                $output->writeln('<info>No changes required.</info>');
                return Command::SUCCESS;
            }

            if ($input->getOption('dry-run')) {
                $output->writeln('');
                $output->writeln('<comment>Dry run — no files were modified.</comment>');
                return Command::SUCCESS;
            }

            if ($input->isInteractive() && !$input->getOption('yes')) {
                $confirmed = $this->getHelper('question')->ask(
                    $input,
                    $output,
                    new ConfirmationQuestion(
                        sprintf('  <question>Apply %d change(s)?</question> [y/N] ', count($plan->changes)),
                        false
                    )
                );

                if (!$confirmed) {
                    $output->writeln('');
                    $output->writeln('<comment>Aborted — no files were modified.</comment>');
                    return Command::SUCCESS;
                }
            }

            $engine->apply($plan);

            $output->writeln('');
            $output->writeln(sprintf('<info>Renamed %s to %s.</info>', $plan->oldRelativePath, $plan->newRelativePath));
            $output->writeln('<fg=gray>Run composer update for the renamed package when you are ready.</fg=gray>');

            return Command::SUCCESS;
        } catch (XMonorepoException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    private function engine(): PackageRenameEngine
    {
        $monorepo     = $this->xMonorepo();
        $config       = $monorepo->getConfig();
        $root         = $monorepo->getMonorepoRoot();
        $packagesDir  = $config->getString('packages_dir', 'packages');
        $packagesPath = str_starts_with($packagesDir, '/')
            ? $packagesDir
            : $root . DIRECTORY_SEPARATOR . $packagesDir;

        return new PackageRenameEngine(
            packagesRootPath: $packagesPath,
            projectRootPath:  $root,
            writer:           new ComposerJsonWriter(),
            githubOrg:        $this->config()->getString('github_org', 'webkernelphp'),
        );
    }

    private function renderPlan(OutputInterface $output, PackageRenamePlan $plan): void
    {
        $output->writeln(sprintf(
            ' <info>%s</info> → <info>%s</info> (<fg=gray>%s → %s</>)',
            $plan->oldName,
            $plan->newName,
            $plan->oldRelativePath,
            $plan->newRelativePath,
        ));

        if (!$plan->hasChanges()) {
            return;
        }

        $output->writeln('');
        $output->writeln('<info>Planned changes</info>');

        foreach ($plan->changes as $change) {
            $output->writeln(match ($change->field) {
                'directory' => sprintf(
                    '  <fg=cyan>directory</> %s → %s',
                    $change->from,
                    $change->to,
                ),
                'replace' => sprintf(
                    '  <fg=cyan>%s</> replace.%s: %s',
                    $this->relativePath($change->file),
                    $change->dependency,
                    $change->to,
                ),
                'require', 'require-dev' => sprintf(
                    '  <fg=cyan>%s</> %s: %s → %s (%s)',
                    $this->relativePath($change->file),
                    $change->field,
                    $change->dependency,
                    $plan->newName,
                    $change->to,
                ),
                default => sprintf(
                    '  <fg=cyan>%s</> %s: %s → %s',
                    $this->relativePath($change->file),
                    $change->field,
                    $change->from ?? '(none)',
                    $change->to,
                ),
            });
        }
    }

    private function renderJson(
        OutputInterface $output,
        PackageRenamePlan $plan,
        bool $dryRun,
        PackageRenameEngine $engine,
    ): int {
        $output->writeln((string) json_encode([
            'old_name'            => $plan->oldName,
            'new_name'            => $plan->newName,
            'old_relative_path'   => $plan->oldRelativePath,
            'new_relative_path'   => $plan->newRelativePath,
            'dry_run'             => $dryRun,
            'changes'             => array_map(static fn (\Webkernel\XMonorepo\Engine\Rename\PackageRenameChange $change): array => [
                'file'       => $change->file,
                'field'      => $change->field,
                'dependency' => $change->dependency,
                'from'       => $change->from,
                'to'         => $change->to,
            ], $plan->changes),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (!$dryRun && $plan->hasChanges()) {
            $engine->apply($plan);
        }

        return Command::SUCCESS;
    }

    private function relativePath(string $absolutePath): string
    {
        $root = rtrim($this->xMonorepo()->getMonorepoRoot(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        return str_starts_with($absolutePath, $root)
            ? substr($absolutePath, strlen($root))
            : $absolutePath;
    }

    /**
     * @return list<InternalPackageRecord>
     */
    private function listPackages(): array
    {
        $monorepo     = $this->xMonorepo();
        $config       = $monorepo->getConfig();
        $root         = $monorepo->getMonorepoRoot();
        $packagesDir  = $config->getString('packages_dir', 'packages');
        $packagesPath = str_starts_with($packagesDir, '/')
            ? $packagesDir
            : $root . DIRECTORY_SEPARATOR . $packagesDir;

        $catalog = (new InternalPackageCatalog($packagesPath, new PackageModuleClassifier()))->load();
        $records = array_values($catalog);

        usort(
            $records,
            static fn (InternalPackageRecord $a, InternalPackageRecord $b): int => strcmp($a->relativePath, $b->relativePath)
        );

        return $records;
    }

    /**
     * @param list<InternalPackageRecord> $packages
     */
    private function renderPackageList(OutputInterface $output, array $packages): void
    {
        $output->writeln(sprintf(' <info>%d</info> packages available for rename', count($packages)));
        $output->writeln('');

        foreach ($packages as $package) {
            $output->writeln(sprintf(
                '  %s <fg=gray>[%s]</>',
                $package->relativePath,
                $package->name,
            ));
        }

        $output->writeln('');
    }

    private function normalizeRelativePath(string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path), '/');

        if (str_starts_with($normalized, 'packages/')) {
            return substr($normalized, strlen('packages/'));
        }

        return $normalized;
    }
}
