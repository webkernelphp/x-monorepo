<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine\Rename;

final readonly class PackageRenameChange
{
    public function __construct(
        public string $file,
        public string $field,
        public ?string $dependency,
        public ?string $from,
        public string $to,
    ) {}
}
