<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine\Rename;

final readonly class PackageRenamePlan
{
    /**
     * @param list<PackageRenameChange> $changes
     */
    public function __construct(
        public string $oldName,
        public string $newName,
        public string $oldRelativePath,
        public string $newRelativePath,
        public array $changes,
    ) {}

    public function hasChanges(): bool
    {
        return $this->changes !== [];
    }
}
