<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine\Sync;

final readonly class VersionSyncOptions
{
    public function __construct(
        public VersionSyncMode $mode,
        public ConstraintOperator $constraintOperator,
        public ?string $targetVersion = null,
        public bool $syncModuleVersions = false,
        public bool $updateRootModuleRequires = false,
        public bool $dryRun = false,
    ) {}

    public function requiresTargetVersion(): bool
    {
        return $this->mode === VersionSyncMode::Uniform;
    }
}
