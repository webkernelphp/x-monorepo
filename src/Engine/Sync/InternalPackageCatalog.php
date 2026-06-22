<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine\Sync;

use Webkernel\XMonorepo\Exceptions\DiscoveryException;

/**
 * Indexes every composer.json found under the monorepo packages directory.
 */
final readonly class InternalPackageCatalog
{
    public function __construct(
        private string $packagesRootPath,
        private PackageModuleClassifier $classifier,
    ) {}

    /**
     * @return array<string, InternalPackageRecord> keyed by composer package name
     * @throws DiscoveryException
     */
    public function load(): array
    {
        if (!is_dir($this->packagesRootPath)) {
            throw new DiscoveryException(
                "Packages directory '{$this->packagesRootPath}' does not exist."
            );
        }

        $catalog = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->packagesRootPath,
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->getFilename() !== 'composer.json') {
                continue;
            }

            $record = $this->tryParseRecord($item->getPath());

            if ($record === null) {
                continue;
            }

            $catalog[$record->name] = $record;
        }

        ksort($catalog);

        return $catalog;
    }

    private function tryParseRecord(string $packageDir): ?InternalPackageRecord
    {
        $composerFile = $packageDir . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_readable($composerFile)) {
            return null;
        }

        $raw = file_get_contents($composerFile);

        if ($raw === false) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $name = is_string($data['name'] ?? null) ? trim($data['name']) : '';

        if ($name === '') {
            return null;
        }

        $type = is_string($data['type'] ?? null) && $data['type'] !== ''
            ? $data['type']
            : 'unknown';

        $version = is_string($data['version'] ?? null) && $data['version'] !== ''
            ? $data['version']
            : null;

        $relativePath = ltrim(
            str_replace($this->packagesRootPath, '', $packageDir),
            DIRECTORY_SEPARATOR
        );

        return new InternalPackageRecord(
            name: $name,
            absolutePath: $packageDir,
            relativePath: $relativePath,
            type: $type,
            version: $version,
            isModule: $this->classifier->isModule($type),
        );
    }
}
