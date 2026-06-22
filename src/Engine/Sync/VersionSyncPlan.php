<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine\Sync;

final readonly class VersionSyncPlan
{
    /**
     * @param VersionSyncChange[]                    $changes
     * @param string[]                               $warnings
     * @param array<string, string>                  $syncedVersions
     * @param array<string, array<string, string>>   $finalConstraints
     */
    public function __construct(
        public VersionSyncMode $mode,
        public ConstraintOperator $constraintOperator,
        public string $targetVersion,
        public string $sampleConstraint,
        public array $changes,
        public array $warnings,
        public array $syncedVersions,
        public array $finalConstraints,
    ) {}

    public function hasChanges(): bool
    {
        return $this->changes !== [];
    }
}
