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
use Webkernel\XMonorepo\Engine\Sync\ComposerJsonWriter;
use Webkernel\XMonorepo\Engine\Sync\ConstraintOperator;
use Webkernel\XMonorepo\Engine\Sync\InternalPackageCatalog;
use Webkernel\XMonorepo\Engine\Sync\InternalPackageRecord;
use Webkernel\XMonorepo\Engine\Sync\PackageModuleClassifier;
use Webkernel\XMonorepo\Engine\Sync\VersionConstraintResolver;
use Webkernel\XMonorepo\Engine\Sync\VersionSyncChange;
use Webkernel\XMonorepo\Engine\Sync\VersionSyncEngine;
use Webkernel\XMonorepo\Engine\Sync\VersionSyncMode;
use Webkernel\XMonorepo\Engine\Sync\VersionSyncOptions;
use Webkernel\XMonorepo\Engine\Sync\VersionSyncPlan;
use Webkernel\XMonorepo\Exceptions\XMonorepoException;
use Webkernel\XWebdev\XWebdev;

final class SyncVersionsCommand extends MonorepoCommand
{
    private ?VersionSyncEngine $engine = null;

    /** @var array<string, InternalPackageRecord>|null */
    private ?array $catalog = null;

    public function __construct(XWebdev $webdev)
    {
        parent::__construct($webdev, 'sync-versions');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Sync internal package versions and cross-references for a release.')
            ->setAliases(['monorepo:sync', 'monorepo:sync-version'])
            ->addArgument('version', InputArgument::OPTIONAL, 'Uniform target version (e.g. 0.12.0). Omit for wizard.')
            ->addOption('lock-current', null, InputOption::VALUE_NONE, 'Keep each package version; only align internal require constraints.')
            ->addOption('operator', 'o', InputOption::VALUE_REQUIRED, 'Constraint operator: >=, ^, =')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned changes without writing files.')
            ->addOption('sync-module-versions', null, InputOption::VALUE_NONE, 'Advanced: also bump module version fields in uniform mode.')
            ->addOption('update-root-module-requires', null, InputOption::VALUE_NONE, 'Advanced: also rewrite module constraints in the root composer.json.')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Apply changes without confirmation.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the plan as JSON.');
    }

    protected function needsInput(InputInterface $input): bool
    {
        return !$this->hasResolvedMode($input)
            || ($input->isInteractive() && $this->resolvedOperator($input) === null);
    }

    protected function showIntro(OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln(' <bg=blue;fg=white> WEBKERNEL MONOREPO VERSION SYNC </>');
        $output->writeln(' <fg=gray>Align internal package versions and composer constraints.</fg=gray>');
        $output->writeln('');
    }

    protected function promptMissing(InputInterface $input, OutputInterface $output): void
    {
        $helper   = $this->getHelper('question');
        $resolver = new VersionConstraintResolver();
        $engine   = $this->engine($input);
        $catalog  = $this->catalog($input);

        $this->renderCatalogSummary($output, $catalog);
        $this->renderVersionInventory($output, $catalog);
        $this->renderDivergenceReport($output, $catalog);

        // ── Operator ─────────────────────────────────────────────────────────
        if ($this->resolvedOperator($input) === null) {
            $sample  = $this->sampleVersionForOperatorPreview($input, $catalog);
            $choices = [];
            foreach (ConstraintOperator::cases() as $op) {
                $choices[$op->value] = sprintf('%s — e.g. %s', $op->label(), $op->example($sample));
            }
            $answer   = $helper->ask($input, $output, new ChoiceQuestion(' <fg=cyan>Constraint operator</> for internal packages: ', $choices, '>='));
            $operator = ConstraintOperator::tryFrom((string) $answer) ?? ConstraintOperator::Floor;
            $input->setOption('operator', $operator->value);
        }

        $operator       = $this->resolvedOperator($input) ?? ConstraintOperator::Floor;
        $uniformTarget  = $this->suggestUniformVersion($catalog);

        // ── Strategy preview ─────────────────────────────────────────────────
        $lockPlan    = $this->previewPlan($engine, new VersionSyncOptions(mode: VersionSyncMode::LockCurrent,  constraintOperator: $operator, dryRun: true));
        $uniformPlan = $this->previewPlan($engine, new VersionSyncOptions(mode: VersionSyncMode::Uniform, constraintOperator: $operator, targetVersion: $uniformTarget, dryRun: true));

        $this->renderStrategyPreviews($output, $lockPlan, $uniformPlan, $uniformTarget, $operator);

        // ── Mode ─────────────────────────────────────────────────────────────
        if (!$this->hasResolvedMode($input)) {
            $modeAnswer = $helper->ask($input, $output, new ChoiceQuestion(
                ' <fg=cyan>Sync strategy</>: ',
                [
                    'lock'    => sprintf('Lock current — %d version, %d constraint(s)',    $this->countVersionChanges($lockPlan),    $this->countConstraintChanges($lockPlan)),
                    'uniform' => sprintf('Uniform → %s — %d version, %d constraint(s)', $uniformTarget, $this->countVersionChanges($uniformPlan), $this->countConstraintChanges($uniformPlan)),
                ],
                'lock'
            ));

            if ($modeAnswer === 'uniform') {
                $input->setOption('lock-current', false);

                if (trim((string) $input->getArgument('version')) === '') {
                    $suggestion = $uniformTarget;
                    while (true) {
                        $answer  = $helper->ask($input, $output, new Question(" <fg=cyan>Target version</> [{$suggestion}]: ", $suggestion));
                        $version = is_string($answer) ? trim($answer) : '';
                        if ($version === '') {
                            continue;
                        }
                        try {
                            $resolver->assertValidVersion($version);
                            $input->setArgument('version', $version);
                            break;
                        } catch (XMonorepoException) {
                            $output->writeln('<error>Invalid SemVer. Try again (e.g. 0.12.0).</error>');
                        }
                    }
                }
            } else {
                $input->setOption('lock-current', true);
            }
        }

        $output->writeln(' <fg=gray>Modules are not touched by this command — declare and version them in the root composer.json.</fg=gray>');
        $output->writeln('');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->isInteractive() && $this->needsInput($input)) {
            return $this->gentleStart($output, [
                'Run without arguments to start the interactive wizard.',
                'Lock current versions: <info>monorepo:sync --lock-current</info>',
                'Uniform release:       <info>monorepo:sync 0.12.0 --operator=">="</info>',
            ]);
        }

        try {
            $options = $this->buildOptions($input);
            $engine  = $this->engine($input);
            $plan    = $engine->plan($options);

            if ($input->getOption('json')) {
                return $this->renderJson($output, $plan, $options, $engine);
            }

            $this->renderPlanHeader($output, $plan);

            if ($plan->warnings !== []) {
                $output->writeln('');
                $output->writeln('<comment>Warnings</comment>');
                foreach ($plan->warnings as $warning) {
                    $output->writeln("  • {$warning}");
                }
            }

            if (!$plan->hasChanges()) {
                $output->writeln('');
                $output->writeln('<info>No changes required — everything is already aligned.</info>');
                return Command::SUCCESS;
            }

            $this->renderChanges($output, $plan);

            if ($options->dryRun) {
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
            $output->writeln(sprintf('<info>Applied %d change(s).</info>', count($plan->changes)));
            return Command::SUCCESS;

        } catch (XMonorepoException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    // ── Engine & catalog (built once, reused) ──────────────────────────────

    private function engine(InputInterface $input): VersionSyncEngine
    {
        if ($this->engine === null) {
            $monorepo     = $this->xMonorepo();
            $config       = $monorepo->getConfig();
            $root         = $monorepo->getMonorepoRoot();
            $packagesDir  = $config->getString('packages_dir', 'packages');
            $packagesPath = str_starts_with($packagesDir, '/')
                ? $packagesDir
                : $root . DIRECTORY_SEPARATOR . $packagesDir;

            $this->engine = new VersionSyncEngine(
                packagesRootPath: $packagesPath,
                projectRootPath:  $root,
                catalog:          new InternalPackageCatalog($packagesPath, new PackageModuleClassifier()),
                constraints:      new VersionConstraintResolver(),
                writer:           new ComposerJsonWriter(),
            );
        }

        return $this->engine;
    }

    /**
     * @return array<string, InternalPackageRecord>
     */
    private function catalog(InputInterface $input): array
    {
        if ($this->catalog === null) {
            $monorepo     = $this->xMonorepo();
            $config       = $monorepo->getConfig();
            $root         = $monorepo->getMonorepoRoot();
            $packagesDir  = $config->getString('packages_dir', 'packages');
            $packagesPath = str_starts_with($packagesDir, '/')
                ? $packagesDir
                : $root . DIRECTORY_SEPARATOR . $packagesDir;

            $this->catalog = (new InternalPackageCatalog($packagesPath, new PackageModuleClassifier()))->load();
        }

        return $this->catalog;
    }

    // ── Options builder ────────────────────────────────────────────────────

    private function buildOptions(InputInterface $input): VersionSyncOptions
    {
        $lockCurrent = (bool) $input->getOption('lock-current');
        $versionArg  = trim((string) $input->getArgument('version'));

        if ($lockCurrent && $versionArg !== '') {
            throw new XMonorepoException('Use either --lock-current or a target version, not both.');
        }

        $mode = $lockCurrent ? VersionSyncMode::LockCurrent : VersionSyncMode::Uniform;

        if ($mode === VersionSyncMode::Uniform && $versionArg === '') {
            throw new XMonorepoException('A target version is required for uniform sync mode.');
        }

        return new VersionSyncOptions(
            mode:                    $mode,
            constraintOperator:      $this->resolvedOperator($input) ?? ConstraintOperator::Floor,
            targetVersion:           $mode === VersionSyncMode::Uniform ? $versionArg : null,
            syncModuleVersions:      (bool) $input->getOption('sync-module-versions'),
            updateRootModuleRequires:(bool) $input->getOption('update-root-module-requires'),
            dryRun:                  (bool) $input->getOption('dry-run'),
        );
    }

    // ── Catalog renderers ──────────────────────────────────────────────────

    /**
     * @param array<string, InternalPackageRecord> $catalog
     */
    private function renderCatalogSummary(OutputInterface $output, array $catalog): void
    {
        $syncable = $modules = 0;
        foreach ($catalog as $record) {
            $record->isModule ? $modules++ : $syncable++;
        }

        $output->writeln(sprintf(
            ' <info>%d</info> internal packages (<info>%d</info> syncable, <info>%d</info> modules)',
            count($catalog), $syncable, $modules
        ));
        $output->writeln('');
    }

    /**
     * @param array<string, InternalPackageRecord> $catalog
     */
    private function renderVersionInventory(OutputInterface $output, array $catalog): void
    {
        /** @var array<string, list<InternalPackageRecord>> $groups */
        $groups = [];
        foreach ($catalog as $record) {
            if ($record->isModule) {
                continue;
            }
            $version = $record->version ?? '(missing)';
            $groups[$version][] = $record;
        }
        uksort($groups, static fn (string $a, string $b): int => version_compare($a, $b));

        $output->writeln(' <comment>Who has what</comment>');
        foreach ($groups as $version => $records) {
            usort($records, static fn (InternalPackageRecord $a, InternalPackageRecord $b): int => strcmp($a->name, $b->name));
            $output->writeln(sprintf('  <info>%s</info> <fg=gray>(%d)</>', $version, count($records)));
            foreach ($records as $record) {
                $output->writeln(sprintf(
                    '    %s <fg=gray>[%s · %s]</>',
                    $record->name,
                    $record->isModule ? 'module' : 'syncable',
                    $record->relativePath
                ));
            }
        }
        $output->writeln('');
    }

    /**
     * @param array<string, InternalPackageRecord> $catalog
     */
    private function renderDivergenceReport(OutputInterface $output, array $catalog): void
    {
        /** @var array<string, list<string>> $byVersion */
        $syncableByVersion = $modulesByVersion = [];
        foreach ($catalog as $record) {
            $v = $record->version ?? '(missing)';
            $record->isModule ? ($modulesByVersion[$v][] = $record->name) : ($syncableByVersion[$v][] = $record->name);
        }

        $output->writeln(' <comment>Divergence</comment>');
        if (count($syncableByVersion) <= 1) {
            $output->writeln(sprintf('  Syncable packages are aligned on <info>%s</info>.', array_key_first($syncableByVersion) ?? '(none)'));
        } else {
            $output->writeln('  Syncable packages use <fg=yellow>' . count($syncableByVersion) . ' different versions</>:');
            uksort($syncableByVersion, static fn (string $a, string $b): int => version_compare($a, $b));
            foreach ($syncableByVersion as $version => $names) {
                sort($names);
                $output->writeln(sprintf('    <info>%s</info> → %s', $version, implode(', ', $names)));
            }
        }

        if ($modulesByVersion !== []) {
            uksort($modulesByVersion, static fn (string $a, string $b): int => version_compare($a, $b));
            $parts = [];
            foreach ($modulesByVersion as $version => $names) {
                sort($names);
                $parts[] = sprintf('%s (%s)', $version, implode(', ', $names));
            }
            $output->writeln('  Modules (root composer.json only): ' . implode(' · ', $parts));
        }

        $output->writeln('');
    }

    // ── Strategy preview ───────────────────────────────────────────────────

    private function renderStrategyPreviews(
        OutputInterface $output,
        VersionSyncPlan $lockPlan,
        VersionSyncPlan $uniformPlan,
        string $uniformTarget,
        ConstraintOperator $operator,
    ): void {
        $output->writeln(sprintf(
            ' <comment>Proposed changes</comment> <fg=gray>(preview with operator %s)</>',
            $operator->value
        ));
        $this->renderStrategyBlock($output, 'LOCK CURRENT',          $lockPlan,    null);
        $this->renderStrategyBlock($output, "UNIFORM → {$uniformTarget}", $uniformPlan, $uniformTarget);
        $output->writeln('');
    }

    private function renderStrategyBlock(
        OutputInterface $output,
        string $title,
        VersionSyncPlan $plan,
        ?string $uniformTarget,
    ): void {
        $versionChanges    = $this->filterChanges($plan, 'version');
        $constraintChanges = $this->filterChanges($plan, null);  // anything that's not 'version'

        $output->writeln("  <fg=cyan>{$title}</>");
        $output->writeln(sprintf('    %d version field(s), %d constraint(s)', count($versionChanges), count($constraintChanges)));

        if ($versionChanges === [] && $constraintChanges === []) {
            $output->writeln('    <fg=gray>Nothing to change — constraints already satisfy current versions.</>');
            return;
        }

        if ($versionChanges !== []) {
            $output->writeln('    Version bumps:');
            foreach (array_slice($versionChanges, 0, 8) as $change) {
                $output->writeln(sprintf('      %s: %s → %s', $change->package, $change->from ?? '(none)', $change->to));
            }
            if (count($versionChanges) > 8) {
                $output->writeln(sprintf('      … and %d more', count($versionChanges) - 8));
            }
        }

        if ($constraintChanges !== []) {
            $output->writeln('    Constraint samples:');
            foreach (array_slice($constraintChanges, 0, 5) as $change) {
                $output->writeln(sprintf('      %s → %s: %s → %s', $change->package, $change->dependency, $change->from, $change->to));
            }
            if (count($constraintChanges) > 5) {
                $output->writeln(sprintf('      … and %d more', count($constraintChanges) - 5));
            }
        }

        if ($uniformTarget !== null && $versionChanges !== []) {
            $behind = array_filter($versionChanges, static fn ($c) => $c->from !== null && version_compare($c->from, $uniformTarget, '<'));
            if ($behind !== []) {
                $output->writeln(sprintf('    <fg=yellow>%d package(s) currently behind %s</>', count($behind), $uniformTarget));
            }
        }
    }

    // ── Plan helpers ───────────────────────────────────────────────────────

    private function previewPlan(VersionSyncEngine $engine, VersionSyncOptions $options): VersionSyncPlan
    {
        try {
            return $engine->plan($options);
        } catch (XMonorepoException) {
            return new VersionSyncPlan(
                mode:               $options->mode,
                constraintOperator: $options->constraintOperator,
                targetVersion:      $options->targetVersion ?? 'lock-current',
                sampleConstraint:   '',
                changes:            [],
                warnings:           [],
                syncedVersions:     [],
                finalConstraints:   [],
            );
        }
    }

    /**
     * @return list<VersionSyncChange>
     */
    private function filterChanges(VersionSyncPlan $plan, ?string $field): array
    {
        if ($field === 'version') {
            return array_values(array_filter($plan->changes, static fn ($c): bool => $c->field === 'version'));
        }
        return array_values(array_filter($plan->changes, static fn ($c): bool => $c->field !== 'version'));
    }

    private function countVersionChanges(VersionSyncPlan $plan): int
    {
        return count($this->filterChanges($plan, 'version'));
    }

    private function countConstraintChanges(VersionSyncPlan $plan): int
    {
        return count($this->filterChanges($plan, null));
    }

    // ── Version utilities ──────────────────────────────────────────────────

    /**
     * @param array<string, InternalPackageRecord> $catalog
     */
    private function suggestUniformVersion(array $catalog): string
    {
        $candidates = [];
        foreach ($catalog as $record) {
            if (!$record->isModule && $record->version !== null) {
                $candidates[] = $record->version;
            }
        }
        if ($candidates === []) {
            return '0.1.0';
        }
        usort($candidates, static fn (string $a, string $b): int => version_compare($b, $a));
        return $candidates[0];
    }

    /**
     * @param array<string, InternalPackageRecord> $catalog
     */
    private function sampleVersionForOperatorPreview(InputInterface $input, array $catalog): string
    {
        $versionArg = trim((string) $input->getArgument('version'));
        if ($versionArg !== '') {
            return $versionArg;
        }
        foreach ($catalog as $record) {
            if (!$record->isModule && $record->version !== null) {
                return $record->version;
            }
        }
        return '0.12.0';
    }

    // ── Mode / operator resolution ─────────────────────────────────────────

    private function hasResolvedMode(InputInterface $input): bool
    {
        return (bool) $input->getOption('lock-current')
            || trim((string) $input->getArgument('version')) !== '';
    }

    private function resolvedOperator(InputInterface $input): ?ConstraintOperator
    {
        $raw = $input->getOption('operator');
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        return ConstraintOperator::tryFrom(trim($raw));
    }

    // ── Output renderers ───────────────────────────────────────────────────

    private function renderPlanHeader(OutputInterface $output, VersionSyncPlan $plan): void
    {
        $output->writeln(sprintf(
            'Mode <info>%s</info> · operator <info>%s</info> · sample <comment>%s</comment>',
            $plan->mode === VersionSyncMode::LockCurrent ? 'lock-current' : $plan->targetVersion,
            $plan->constraintOperator->value,
            $plan->sampleConstraint,
        ));
    }

    private function renderChanges(OutputInterface $output, VersionSyncPlan $plan): void
    {
        $output->writeln('');
        $output->writeln('<info>Planned changes</info>');
        foreach ($plan->changes as $change) {
            if ($change->field === 'version') {
                $output->writeln(sprintf(
                    '  <fg=cyan>%s</> version: %s → %s (%s)',
                    $change->package, $change->from ?? '(none)', $change->to, $change->reason
                ));
            } else {
                $output->writeln(sprintf(
                    '  <fg=cyan>%s</> %s.%s: %s → %s',
                    $change->package, $change->field, $change->dependency, $change->from, $change->to
                ));
            }
        }
    }

    private function renderJson(OutputInterface $output, VersionSyncPlan $plan, VersionSyncOptions $options, VersionSyncEngine $engine): int
    {
        $output->writeln((string) json_encode([
            'mode'               => $plan->mode->value,
            'constraint_operator'=> $plan->constraintOperator->value,
            'target_version'     => $plan->targetVersion,
            'sample_constraint'  => $plan->sampleConstraint,
            'dry_run'            => $options->dryRun,
            'changes'            => array_map(static fn ($c): array => [
                'file'       => $c->file,
                'package'    => $c->package,
                'field'      => $c->field,
                'dependency' => $c->dependency,
                'from'       => $c->from,
                'to'         => $c->to,
                'reason'     => $c->reason,
            ], $plan->changes),
            'warnings'           => $plan->warnings,
            'synced_versions'    => $plan->syncedVersions,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (!$options->dryRun && $plan->hasChanges()) {
            $engine->apply($plan);
        }

        return Command::SUCCESS;
    }
}
