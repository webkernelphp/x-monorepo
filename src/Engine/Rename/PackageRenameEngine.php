<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine\Rename;

use Webkernel\XMonorepo\Engine\Sync\ComposerJsonWriter;
use Webkernel\XMonorepo\Engine\Sync\InternalPackageCatalog;
use Webkernel\XMonorepo\Engine\Sync\PackageModuleClassifier;
use Webkernel\XMonorepo\Exceptions\XMonorepoException;

/**
 * Renames a monorepo package directory and aligns composer metadata.
 */
final readonly class PackageRenameEngine
{
    public function __construct(
        private string $packagesRootPath,
        private string $projectRootPath,
        private ComposerJsonWriter $writer,
        private string $githubOrg = 'webkernelphp',
    ) {}

    public function plan(string $from, string $to): PackageRenamePlan
    {
        $oldRelative = $this->normalizeRelativePath($from);
        $newRelative = $this->normalizeRelativePath($to);

        if ($oldRelative === $newRelative) {
            throw new XMonorepoException('Source and target package paths must differ.');
        }

        $oldAbsolute = $this->packagesRootPath . DIRECTORY_SEPARATOR . $oldRelative;
        $newAbsolute = $this->packagesRootPath . DIRECTORY_SEPARATOR . $newRelative;

        if (!is_dir($oldAbsolute)) {
            throw new XMonorepoException("Package directory not found: {$oldRelative}");
        }

        if (is_dir($newAbsolute)) {
            throw new XMonorepoException("Target directory already exists: {$newRelative}");
        }

        $composerPath = $oldAbsolute . DIRECTORY_SEPARATOR . 'composer.json';

        if (!is_readable($composerPath)) {
            throw new XMonorepoException("composer.json is not readable: {$composerPath}");
        }

        $sourceData = $this->writer->read($composerPath);
        $oldName    = is_string($sourceData['name'] ?? null) ? trim($sourceData['name']) : '';

        if ($oldName === '' || !str_contains($oldName, '/')) {
            throw new XMonorepoException("Package '{$oldRelative}' has no valid composer name.");
        }

        [$vendor] = explode('/', $oldName, 2);
        $oldSlug  = basename($oldRelative);
        $newSlug  = basename($newRelative);
        $newName  = "{$vendor}/{$newSlug}";

        $catalog = (new InternalPackageCatalog($this->packagesRootPath, new PackageModuleClassifier()))->load();

        if (isset($catalog[$newName])) {
            throw new XMonorepoException("Composer package '{$newName}' already exists.");
        }

        $webkernelExtra = is_array($sourceData['extra']['webkernel'] ?? null)
            ? $sourceData['extra']['webkernel']
            : [];

        $oldRepo = is_string($webkernelExtra['package_repo'] ?? null)
            ? trim($webkernelExtra['package_repo'])
            : '';

        $oldPrefix = is_string($webkernelExtra['prefix'] ?? null)
            ? trim($webkernelExtra['prefix'])
            : '';

        $newRepo   = $this->derivePackageRepo($oldRepo, $oldSlug, $newSlug);
        $newPrefix = $this->derivePrefix($oldPrefix, $oldSlug, $newSlug);

        /** @var list<PackageRenameChange> $changes */
        $changes = [];

        $changes[] = new PackageRenameChange(
            file: $composerPath,
            field: 'name',
            dependency: null,
            from: $oldName,
            to: $newName,
        );

        $changes[] = new PackageRenameChange(
            file: $composerPath,
            field: 'replace',
            dependency: $oldName,
            from: null,
            to: '*',
        );

        if ($newRepo !== $oldRepo) {
            $changes[] = new PackageRenameChange(
                file: $composerPath,
                field: 'extra.webkernel.package_repo',
                dependency: null,
                from: $oldRepo !== '' ? $oldRepo : null,
                to: $newRepo,
            );
        }

        if ($newPrefix !== $oldPrefix) {
            $changes[] = new PackageRenameChange(
                file: $composerPath,
                field: 'extra.webkernel.prefix',
                dependency: null,
                from: $oldPrefix !== '' ? $oldPrefix : null,
                to: $newPrefix,
            );
        }

        foreach ($this->composerJsonPaths() as $path) {
            if ($path === $composerPath) {
                continue;
            }

            $data = $this->writer->read($path);

            foreach (['require', 'require-dev'] as $section) {
                if (!isset($data[$section])) {
                    continue;
                }
                if (!is_array($data[$section])) {
                    continue;
                }
                if (!isset($data[$section][$oldName])) {
                    continue;
                }
                if (!is_string($data[$section][$oldName])) {
                    continue;
                }
                $changes[] = new PackageRenameChange(
                    file: $path,
                    field: $section,
                    dependency: $oldName,
                    from: $data[$section][$oldName],
                    to: $data[$section][$oldName],
                );
            }
        }

        $changes[] = new PackageRenameChange(
            file: $oldAbsolute,
            field: 'directory',
            dependency: null,
            from: $oldRelative,
            to: $newRelative,
        );

        return new PackageRenamePlan(
            oldName: $oldName,
            newName: $newName,
            oldRelativePath: $oldRelative,
            newRelativePath: $newRelative,
            changes: $changes,
        );
    }

    public function apply(PackageRenamePlan $plan): void
    {
        $directoryChange = null;

        foreach ($plan->changes as $change) {
            if ($change->field === 'directory') {
                $directoryChange = $change;
                continue;
            }

            $this->applyComposerChange($change, $plan);
        }

        if ($directoryChange === null) {
            throw new XMonorepoException('Rename plan is missing a directory change.');
        }

        $oldAbsolute = $this->packagesRootPath . DIRECTORY_SEPARATOR . $plan->oldRelativePath;
        $newAbsolute = $this->packagesRootPath . DIRECTORY_SEPARATOR . $plan->newRelativePath;

        if (!rename($oldAbsolute, $newAbsolute)) {
            throw new XMonorepoException(
                "Failed to rename '{$plan->oldRelativePath}' to '{$plan->newRelativePath}'."
            );
        }
    }

    private function applyComposerChange(PackageRenameChange $change, PackageRenamePlan $plan): void
    {
        $data = $this->writer->read($change->file);

        match ($change->field) {
            'name' => $data['name'] = $change->to,
            'replace' => $this->applyReplace($data, $change),
            'extra.webkernel.package_repo' => $this->applyWebkernelExtra($data, 'package_repo', $change->to),
            'extra.webkernel.prefix' => $this->applyWebkernelExtra($data, 'prefix', $change->to),
            'require', 'require-dev' => $this->applyRequireRename($data, $change, $plan),
            default => throw new XMonorepoException("Unsupported rename field '{$change->field}'."),
        };

        $this->writer->write($change->file, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyReplace(array &$data, PackageRenameChange $change): void
    {
        if ($change->dependency === null) {
            throw new XMonorepoException('Replace change is missing the replaced package name.');
        }

        if (!isset($data['replace']) || !is_array($data['replace'])) {
            $data['replace'] = [];
        }

        $data['replace'][$change->dependency] = $change->to;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyWebkernelExtra(array &$data, string $key, string $value): void
    {
        if (!isset($data['extra']) || !is_array($data['extra'])) {
            $data['extra'] = [];
        }

        if (!isset($data['extra']['webkernel']) || !is_array($data['extra']['webkernel'])) {
            $data['extra']['webkernel'] = [];
        }

        $data['extra']['webkernel'][$key] = $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function applyRequireRename(array &$data, PackageRenameChange $change, PackageRenamePlan $plan): void
    {
        if ($change->dependency === null || !isset($data[$change->field]) || !is_array($data[$change->field])) {
            throw new XMonorepoException("Cannot rewrite {$change->field} in {$change->file}.");
        }

        $constraint = $data[$change->field][$change->dependency];
        unset($data[$change->field][$change->dependency]);
        $data[$change->field][$plan->newName] = $constraint;
    }

    private function normalizeRelativePath(string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path), '/');

        if (str_starts_with($normalized, 'packages/')) {
            $normalized = substr($normalized, strlen('packages/'));
        }

        if ($normalized === '' || str_contains($normalized, '..')) {
            throw new XMonorepoException("Invalid package path '{$path}'.");
        }

        return $normalized;
    }

    private function derivePackageRepo(string $currentRepo, string $oldSlug, string $newSlug): string
    {
        if ($currentRepo !== '' && str_contains($currentRepo, $oldSlug)) {
            return str_replace($oldSlug, $newSlug, $currentRepo);
        }

        return "git@github.com:{$this->githubOrg}/{$newSlug}.git";
    }

    private function derivePrefix(string $currentPrefix, string $oldSlug, string $newSlug): string
    {
        if ($currentPrefix === '') {
            return $newSlug;
        }

        if ($currentPrefix === $oldSlug || str_contains($currentPrefix, $oldSlug)) {
            return str_replace($oldSlug, $newSlug, $currentPrefix);
        }

        return $currentPrefix;
    }

    /**
     * @return list<string>
     */
    private function composerJsonPaths(): array
    {
        $paths = [];
        $rootComposer = $this->projectRootPath . DIRECTORY_SEPARATOR . 'composer.json';

        if (is_readable($rootComposer)) {
            $paths[] = $rootComposer;
        }

        if (!is_dir($this->packagesRootPath)) {
            return $paths;
        }

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

            $paths[] = $item->getPathname();
        }

        sort($paths);

        return $paths;
    }
}
