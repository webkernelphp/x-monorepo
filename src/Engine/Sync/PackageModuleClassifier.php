<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine\Sync;

/**
 * Distinguishes business/platform modules from syncable internal packages.
 */
final readonly class PackageModuleClassifier
{
    private const array MODULE_TYPES = [
        'webkernel-business-module',
        'webkernel-business-module-feature',
        'webkernel-platform-module',
        'webkernel-platform-module-feature',
    ];

    public function isModule(string $composerType): bool
    {
        return in_array($composerType, self::MODULE_TYPES, true);
    }
}
