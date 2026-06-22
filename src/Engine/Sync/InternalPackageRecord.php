<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine\Sync;

final readonly class InternalPackageRecord
{
    public function __construct(
        public string $name,
        public string $absolutePath,
        public string $relativePath,
        public string $type,
        public ?string $version,
        public bool $isModule,
    ) {}
}
