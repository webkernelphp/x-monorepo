<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine\Sync;

use Webkernel\XMonorepo\Exceptions\XMonorepoException;

/**
 * Aligns internal package versions and cross-references for a monorepo release.
 *
 * Modules are excluded from bulk require updates: they stay explicitly declared
 * in the root project composer.json.
 */
final readonly class VersionSyncEngine
{
    public function __construct(
        private string $projectRootPath,
        private InternalPackageCatalog $catalog,
        private VersionConstraintResolver $constraints,
        private ComposerJsonWriter $writer,
    ) {}

    public function plan(VersionSyncOptions $options): VersionSyncPlan
    {
        $catalog = $this->catalog->load();

        if ($options->requiresTargetVersion()) {
            if ($options->targetVersion === null || trim($options->targetVersion) === '') {
                throw new XMonorepoException('A target version is required for uniform sync mode.');
            }

            $this->constraints->assertValidVersion($options->targetVersion);
        }

        $syncableNames = [];
        $moduleNames = [];

        foreach ($catalog as $name => $record) {
            if ($record->isModule) {
                $moduleNames[$name] = true;
                continue;
            }

            $syncableNames[$name] = true;
        }

        $syncedVersions = $this->resolveSyncedVersions($catalog, $options);
        $changes = [];
        $warnings = [];
        $finalConstraints = [];

        foreach ($catalog as $record) {
            $composerPath = $record->absolutePath . DIRECTORY_SEPARATOR . 'composer.json';
            $data = $this->writer->read($composerPath);
            $finalConstraints[$record->name] = [];

            $this->planVersionField($changes, $composerPath, $record, $data, $options, $syncedVersions);

            foreach (['require', 'require-dev'] as $section) {
                if (!isset($data[$section])) {
                    continue;
                }
                if (!is_array($data[$section])) {
                    continue;
                }
                foreach ($data[$section] as $dependency => $constraint) {
                    if (!is_string($dependency)) {
                        continue;
                    }
                    if (!is_string($constraint)) {
                        continue;
                    }
                    if (!isset($catalog[$dependency])) {
                        continue;
                    }

                    $depRecord = $catalog[$dependency];
                    $finalConstraints[$record->name][$dependency] = $constraint;

                    if ($depRecord->isModule) {
                        $warnings[$this->moduleRequireWarningKey($record->name, $dependency)] =
                            "Module '{$dependency}' is required by '{$record->name}' — modules should be required only in the root composer.json.";

                        continue;
                    }

                    if (!isset($syncableNames[$dependency])) {
                        continue;
                    }

                    $newConstraint = $this->constraints->buildConstraint(
                        $syncedVersions[$dependency],
                        $options->constraintOperator
                    );

                    if (!$this->constraints->shouldAlignConstraint($constraint, $newConstraint)) {
                        continue;
                    }

                    $changes[] = new VersionSyncChange(
                        file: $composerPath,
                        package: $record->name,
                        field: $section,
                        dependency: $dependency,
                        from: $constraint,
                        to: $newConstraint,
                        reason: 'sync internal dependency',
                    );

                    $finalConstraints[$record->name][$dependency] = $newConstraint;
                }
            }
        }

        $rootComposer = $this->projectRootPath . DIRECTORY_SEPARATOR . 'composer.json';

        if (is_readable($rootComposer)) {
            $rootData = $this->writer->read($rootComposer);
            $rootName = is_string($rootData['name'] ?? null) ? $rootData['name'] : 'root-project';
            $finalConstraints[$rootName] = [];

            foreach (['require', 'require-dev'] as $section) {
                if (!isset($rootData[$section])) {
                    continue;
                }
                if (!is_array($rootData[$section])) {
                    continue;
                }
                foreach ($rootData[$section] as $dependency => $constraint) {
                    if (!is_string($dependency)) {
                        continue;
                    }
                    if (!is_string($constraint)) {
                        continue;
                    }
                    if (!isset($catalog[$dependency])) {
                        continue;
                    }

                    $depRecord = $catalog[$dependency];
                    $finalConstraints[$rootName][$dependency] = $constraint;

                    if ($depRecord->isModule && !$options->updateRootModuleRequires) {
                        continue;
                    }

                    $newConstraint = $this->constraints->buildConstraint(
                        $syncedVersions[$dependency],
                        $options->constraintOperator
                    );

                    if ($depRecord->isModule) {
                        if (!$this->constraints->mustRewriteConstraint($constraint, $newConstraint, $syncedVersions[$dependency])) {
                            continue;
                        }
                    } elseif (!isset($syncableNames[$dependency])) {
                        continue;
                    } elseif (!$this->constraints->shouldAlignConstraint($constraint, $newConstraint)) {
                        continue;
                    }

                    $changes[] = new VersionSyncChange(
                        file: $rootComposer,
                        package: $rootName,
                        field: $section,
                        dependency: $dependency,
                        from: $constraint,
                        to: $newConstraint,
                        reason: $depRecord->isModule
                            ? 'sync root module require'
                            : 'sync root internal dependency',
                    );

                    $finalConstraints[$rootName][$dependency] = $newConstraint;
                }
            }
        }

        $this->assertDependencyGraph($catalog, $syncedVersions, $finalConstraints);

        $sampleVersion = $options->targetVersion
            ?? $this->sampleVersion($syncedVersions)
            ?? '0.1.0';

        return new VersionSyncPlan(
            mode: $options->mode,
            constraintOperator: $options->constraintOperator,
            targetVersion: $options->targetVersion ?? 'lock-current',
            sampleConstraint: $this->constraints->buildConstraint($sampleVersion, $options->constraintOperator),
            changes: $changes,
            warnings: array_values($warnings),
            syncedVersions: $syncedVersions,
            finalConstraints: $finalConstraints,
        );
    }

    public function apply(VersionSyncPlan $plan): void
    {
        if (!$plan->hasChanges()) {
            return;
        }

        $this->assertDependencyGraph(
            $this->catalog->load(),
            $plan->syncedVersions,
            $plan->finalConstraints
        );

        $grouped = [];

        foreach ($plan->changes as $change) {
            $grouped[$change->file][] = $change;
        }

        foreach ($grouped as $file => $fileChanges) {
            $data = $this->writer->read($file);

            foreach ($fileChanges as $change) {
                if ($change->field === 'version') {
                    $data['version'] = $change->to;
                    continue;
                }

                if (!isset($data[$change->field]) || !is_array($data[$change->field])) {
                    $data[$change->field] = [];
                }

                if ($change->dependency === null) {
                    continue;
                }

                $data[$change->field][$change->dependency] = $change->to;
            }

            $this->writer->write($file, $data);
        }
    }

    /**
     * @param array<string, InternalPackageRecord> $catalog
     * @return array<string, string>
     */
    private function resolveSyncedVersions(array $catalog, VersionSyncOptions $options): array
    {
        $syncedVersions = [];

        foreach ($catalog as $name => $record) {
            if ($record->isModule) {
                if ($options->syncModuleVersions && $options->mode === VersionSyncMode::Uniform) {
                    $syncedVersions[$name] = $options->targetVersion
                        ?? throw new XMonorepoException('Target version is required when syncing module versions.');
                } else {
                    $syncedVersions[$name] = $record->version
                        ?? throw new XMonorepoException("Module '{$name}' has no version field in composer.json.");
                }

                continue;
            }

            if ($options->mode === VersionSyncMode::LockCurrent) {
                $syncedVersions[$name] = $record->version
                    ?? throw new XMonorepoException("Package '{$name}' has no version field in composer.json.");
                continue;
            }

            $syncedVersions[$name] = $options->targetVersion
                ?? throw new XMonorepoException('Target version is required for uniform sync mode.');
        }

        return $syncedVersions;
    }

    /**
     * @param array<string, string> $syncedVersions
     */
    private function sampleVersion(array $syncedVersions): ?string
    {
        foreach ($syncedVersions as $version) {
            return $version;
        }

        return null;
    }

    /**
     * @param array<string, InternalPackageRecord> $catalog
     * @param array<string, string>                $syncedVersions
     * @param array<string, array<string, string>> $finalConstraints
     */
    private function assertDependencyGraph(
        array $catalog,
        array $syncedVersions,
        array $finalConstraints,
    ): void {
        foreach ($catalog as $consumer => $record) {
            $composerPath = $record->absolutePath . DIRECTORY_SEPARATOR . 'composer.json';
            $data = $this->writer->read($composerPath);

            foreach (['require', 'require-dev'] as $section) {
                if (!isset($data[$section])) {
                    continue;
                }
                if (!is_array($data[$section])) {
                    continue;
                }
                foreach ($data[$section] as $dependency => $constraint) {
                    if (!is_string($dependency)) {
                        continue;
                    }
                    if (!is_string($constraint)) {
                        continue;
                    }
                    if (!isset($catalog[$dependency], $syncedVersions[$dependency])) {
                        continue;
                    }

                    $effectiveConstraint = $finalConstraints[$consumer][$dependency] ?? $constraint;

                    if ($this->constraints->satisfies($syncedVersions[$dependency], $effectiveConstraint)) {
                        continue;
                    }

                    throw new XMonorepoException(
                        "Dependency graph mismatch: '{$consumer}' requires '{$dependency}' with '{$effectiveConstraint}', "
                        . "but synced version is '{$syncedVersions[$dependency]}'."
                    );
                }
            }
        }

        $rootComposer = $this->projectRootPath . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_readable($rootComposer)) {
            return;
        }

        $rootData = $this->writer->read($rootComposer);
        $rootName = is_string($rootData['name'] ?? null) ? $rootData['name'] : 'root-project';

        foreach (['require', 'require-dev'] as $section) {
            if (!isset($rootData[$section])) {
                continue;
            }
            if (!is_array($rootData[$section])) {
                continue;
            }
            foreach ($rootData[$section] as $dependency => $constraint) {
                if (!is_string($dependency)) {
                    continue;
                }
                if (!is_string($constraint)) {
                    continue;
                }
                if (!isset($catalog[$dependency], $syncedVersions[$dependency])) {
                    continue;
                }

                $effectiveConstraint = $finalConstraints[$rootName][$dependency] ?? $constraint;

                if ($this->constraints->satisfies($syncedVersions[$dependency], $effectiveConstraint)) {
                    continue;
                }

                throw new XMonorepoException(
                    "Root project requires '{$dependency}' with '{$effectiveConstraint}', "
                    . "but synced version is '{$syncedVersions[$dependency]}'. "
                    . 'Use --update-root-module-requires for modules or adjust the root constraint manually.'
                );
            }
        }
    }

    /**
     * @param VersionSyncChange[]              $changes
     * @param array<string, mixed>             $data
     * @param array<string, string>            $syncedVersions
     */
    private function planVersionField(
        array &$changes,
        string $composerPath,
        InternalPackageRecord $record,
        array $data,
        VersionSyncOptions $options,
        array $syncedVersions,
    ): void {
        if ($options->mode === VersionSyncMode::LockCurrent) {
            return;
        }

        if ($record->isModule && !$options->syncModuleVersions) {
            return;
        }

        $targetVersion = $syncedVersions[$record->name];
        $current = is_string($data['version'] ?? null) ? $data['version'] : null;

        if ($current === $targetVersion) {
            return;
        }

        $bump = $this->constraints->bumpType($current, $targetVersion);

        $changes[] = new VersionSyncChange(
            file: $composerPath,
            package: $record->name,
            field: 'version',
            dependency: null,
            from: $current,
            to: $targetVersion,
            reason: $bump === 'none' ? 'set version' : "{$bump} version bump",
        );
    }

    private function moduleRequireWarningKey(string $consumer, string $module): string
    {
        return "{$consumer}::{$module}";
    }
}
