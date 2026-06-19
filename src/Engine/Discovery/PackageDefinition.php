<?php declare(strict_types=1);
namespace Webkernel\XMonorepo\Engine\Discovery;
/**
 * Immutable description of a discovered sub-package that is eligible for splitting.
 */
final class PackageDefinition
{
    /**
     * @param string $name           Composer package name (e.g. "webkernel/my-package").
     * @param string $absolutePath   Absolute filesystem path to the package root.
     * @param string $relativePath   Path relative to the monorepo packages directory.
     * @param string $splitRepoUrl   Remote URL of the split target repository.
     * @param string $defaultBranch  Branch to push to in the split repository.
     * @param string $type           Package type (e.g. "component", "engine", "bundle").
     */
    public function __construct(
        private readonly string $name,
        private readonly string $absolutePath,
        private readonly string $relativePath,
        private readonly string $splitRepoUrl,
        private readonly string $defaultBranch,
        private readonly string $type = 'unknown',
    ) {}
    public function getName(): string
    {
        return $this->name;
    }
    public function getAbsolutePath(): string
    {
        return $this->absolutePath;
    }
    public function getRelativePath(): string
    {
        return $this->relativePath;
    }
    public function getSplitRepoUrl(): string
    {
        return $this->splitRepoUrl;
    }
    public function getDefaultBranch(): string
    {
        return $this->defaultBranch;
    }
    public function getType(): string
    {
        return $this->type;
    }
}
