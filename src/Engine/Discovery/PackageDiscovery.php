<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine\Discovery;

use Webkernel\XMonorepo\Exceptions\DiscoveryException;

/**
 * Scans a packages directory and returns all sub-packages eligible for splitting.
 *
 * Eligibility: the package has a composer.json that declares
 * extra.webkernel.package_repo with a non-empty remote URL.
 */
final class PackageDiscovery
{
    /**
     * @param string   $packagesRootPath Absolute path to the packages directory.
     * @param string   $defaultBranch    Branch name used when the package does not override it.
     * @param string[] $allowedPrefixes  If non-empty, only packages whose relative path starts with one of these are returned.
     * @param string[] $excludedPackages Composer package names to exclude by exact match.
     */
    public function __construct(
        private readonly string $packagesRootPath,
        private readonly string $defaultBranch = 'main',
        private readonly array $allowedPrefixes = [],
        private readonly array $excludedPackages = []
    ) {}

    /**
     * Discover all eligible packages.
     *
     * @return PackageDefinition[]
     * @throws DiscoveryException
     */
    public function discover(): array
    {
        if (!is_dir($this->packagesRootPath)) {
            throw new DiscoveryException(
                "Packages directory '{$this->packagesRootPath}' does not exist."
            );
        }

        $packages = [];
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

            $packageDir = $item->getPath();
            $definition = $this->tryBuildDefinition($packageDir);

            if ($definition === null) {
                continue;
            }

            $packages[] = $definition;
        }

        // Sort by name for stable ordering.
        usort($packages, static fn (PackageDefinition $a, PackageDefinition $b) => strcmp($a->getName(), $b->getName()));

        return $packages;
    }

    /**
     * Attempt to parse a composer.json and produce a PackageDefinition.
     * Returns null if the package is not eligible (missing package_repo, excluded, etc.).
     */
    private function tryBuildDefinition(string $packageDir): ?PackageDefinition
    {
        $composerFile = $packageDir . DIRECTORY_SEPARATOR . 'composer.json';

        if (!file_exists($composerFile)) {
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

        $splitRepoUrl = $data['extra']['webkernel']['package_repo'] ?? null;

        if (!is_string($splitRepoUrl) || $splitRepoUrl === '') {
            return null;
        }

        $packageName = is_string($data['name'] ?? null) ? $data['name'] : '';

        if ($packageName === '' || in_array($packageName, $this->excludedPackages, true)) {
            return null;
        }

        $relativePath = ltrim(
            str_replace($this->packagesRootPath, '', $packageDir),
            DIRECTORY_SEPARATOR
        );

        if ($this->allowedPrefixes !== []) {
            $allowed = false;

            foreach ($this->allowedPrefixes as $prefix) {
                if (str_starts_with($relativePath, $prefix)) {
                    $allowed = true;
                    break;
                }
            }

            if (!$allowed) {
                return null;
            }
        }

        $branch = is_string($data['extra']['webkernel']['branch'] ?? null)
            ? $data['extra']['webkernel']['branch']
            : $this->defaultBranch;

        return new PackageDefinition(
            $packageName,
            $packageDir,
            $relativePath,
            $splitRepoUrl,
            $branch
        );
    }
}
