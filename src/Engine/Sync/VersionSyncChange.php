<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine\Sync;

final readonly class VersionSyncChange
{
    public function __construct(
        public string $file,
        public string $package,
        public string $field,
        public ?string $dependency,
        public ?string $from,
        public string $to,
        public string $reason,
    ) {}
}
