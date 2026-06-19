<?php declare(strict_types=1);

namespace Webkernel\XMonorepo;

use Webkernel\StdGit\Operations\IOperationStateStore;
use Webkernel\StdGit\StdGit;
use Webkernel\XMonorepo\Config\ConfigLoader;
use Webkernel\XMonorepo\Engine\ChangelogWriter;
use Webkernel\XMonorepo\Engine\Discovery\PackageDiscovery;
use Webkernel\XMonorepo\Engine\SplitEngine;
use Webkernel\XMonorepo\Engine\State\StateManager;
use Webkernel\XMonorepo\Exceptions\XMonorepoException;

/**
 * Composition root and fluent entry point for the XMonorepo orchestration layer.
 *
 * Typical usage:
 *
 *   $xMonorepo = (new XMonorepo(new StdGit($runner)))
 *       ->dotGitRoot(webapp_path())
 *       ->connect(username: $username, repo: $repo)
 *       ->ensureIfCommitted(
 *           ifNotCommit:      "Pre Tag $tag commit",
 *           tag:              $tag,
 *           splitFrom:        $packagesRootDir,
 *           splitStateFile:   $splitStateFileDest,
 *           makeSplitReposRo: true,
 *       );
 */
final class XMonorepo
{
    private ?string $monorepoRoot    = null;
    private ?string $remoteUsername  = null;
    private ?string $remoteRepo      = null;
    private ?ConfigLoader $config    = null;

    public function __construct(private readonly StdGit $git)
    {
        // Allow construction with default config (no file required until fluent calls need it).
    }

    // -------------------------------------------------------------------------
    // Fluent configuration chain
    // -------------------------------------------------------------------------

    /**
     * Set the absolute path where the monorepo .git directory lives.
     *
     * @return static
     */
    public function dotGitRoot(string $path): static
    {
        $resolved = realpath($path);

        if ($resolved === false) {
            throw new XMonorepoException("Monorepo root path '$path' not found.");
        }

        $this->monorepoRoot = $resolved;
        return $this;
    }

    /**
     * Configure the remote connection credentials/identity used when building authenticated push URLs.
     *
     * @return static
     */
    public function connect(string $username, string $repo): static
    {
        $this->remoteUsername = $username;
        $this->remoteRepo     = $repo;
        return $this;
    }

    /**
     * Load configuration from a file or array.
     *
     * @param  string|array<string, mixed> $source
     * @return static
     */
    public function withConfig(string|array $source): static
    {
        $this->config = new ConfigLoader($source);
        return $this;
    }

    /**
     * The main operation: ensure the monorepo has a commit (committing any pending
     * changes if necessary), apply a tag, then split each sub-package into its own
     * repository and push.
     *
     * @param  string                                                   $ifNotCommit      Commit message used when there are uncommitted changes.
     * @param  string                                                   $tag              Version tag to apply and push.
     * @param  string                                                   $splitFrom        Absolute path to the packages root directory.
     * @param  string                                                   $splitStateFile   Absolute path to the JSON state file.
     * @param  bool                                                     $makeSplitReposRo When true, push URLs for split remotes are set to a no-op URL.
     * @param  (callable(string $type, string $chunk): void)|null       $output           Live git output callback.
     * @return static
     * @throws XMonorepoException
     */
    public function ensureIfCommitted(
        string $ifNotCommit,
        string $tag,
        string $splitFrom,
        string $splitStateFile,
        bool $makeSplitReposRo = true,
        ?callable $output = null
    ): static {
        $root = $this->requireMonorepoRoot();
        $repo = $this->git->open($root);

        // 1. Commit any pending changes.
        if ($repo->hasChanges()) {
            $repo->addAllChanges()->commit($ifNotCommit);
        }

        // 2. Apply the tag (idempotent: skip if already exists).
        $existingTags = array_map(
            static fn ($t) => $t->getName(),
            $repo->getTags()
        );

        if (!in_array($tag, $existingTags, true)) {
            $repo->createTag($tag);
        }

        // 3. Split all packages.
        $stateDir      = dirname($splitStateFile);
        $stateFilename = basename($splitStateFile);
        $stateStore    = $this->git->createStateStore($stateDir, $stateFilename);
        $stateManager  = new StateManager($stateStore);

        $config    = $this->resolvedConfig();
        $discovery = $this->buildDiscovery($splitFrom, $config);
        $packages  = $discovery->discover();

        $engine = new SplitEngine(
            $this->git,
            $stateManager,
            $this->buildChangelogWriter($config),
            $root,
            $config->getString('git_name', 'Webkernel Release Bot'),
            $config->getString('git_email', 'releases@webkernel.io'),
            $config->getString('push_url_mode', 'auto')
        );

        foreach ($packages as $package) {
            $engine->split($package, $tag, false, $output);
        }

        return $this;
    }

    // -------------------------------------------------------------------------
    // Factory helpers (used by commands and internal methods)
    // -------------------------------------------------------------------------

    public function getConfig(): ConfigLoader
    {
        return $this->resolvedConfig();
    }

    public function createDiscovery(): PackageDiscovery
    {
        $config       = $this->resolvedConfig();
        $root         = $this->requireMonorepoRoot();
        $packagesDir  = $config->getString('packages_dir', 'packages');
        $packagesPath = str_starts_with($packagesDir, '/')
            ? $packagesDir
            : $root . DIRECTORY_SEPARATOR . $packagesDir;

        return $this->buildDiscovery($packagesPath, $config);
    }

    public function createSplitEngine(): SplitEngine
    {
        $config       = $this->resolvedConfig();
        $stateFilePath = $config->getString('state_file', 'storage/x-monorepo-state.json');

        if (!str_starts_with($stateFilePath, '/')) {
            $stateFilePath = $this->requireMonorepoRoot() . DIRECTORY_SEPARATOR . $stateFilePath;
        }

        $stateDir      = dirname($stateFilePath);
        $stateFilename = basename($stateFilePath);
        $stateStore    = $this->git->createStateStore($stateDir, $stateFilename);

        return new SplitEngine(
            $this->git,
            new StateManager($stateStore),
            $this->buildChangelogWriter($config),
            $this->requireMonorepoRoot(),
            $config->getString('git_name', 'Webkernel Release Bot'),
            $config->getString('git_email', 'releases@webkernel.io'),
            $config->getString('push_url_mode', 'auto')
        );
    }

    public function createStateManager(): StateManager
    {
        $config       = $this->resolvedConfig();
        $stateFilePath = $config->getString('state_file', 'storage/x-monorepo-state.json');

        if (!str_starts_with($stateFilePath, '/')) {
            $stateFilePath = $this->requireMonorepoRoot() . DIRECTORY_SEPARATOR . $stateFilePath;
        }

        $stateStore = $this->git->createStateStore(dirname($stateFilePath), basename($stateFilePath));
        return new StateManager($stateStore);
    }

    public function getGit(): StdGit
    {
        return $this->git;
    }

    public function getMonorepoRoot(): string
    {
        return $this->requireMonorepoRoot();
    }

    // -------------------------------------------------------------------------

    private function requireMonorepoRoot(): string
    {
        if ($this->monorepoRoot === null) {
            throw new XMonorepoException(
                'Monorepo root has not been set. Call dotGitRoot() first.'
            );
        }

        return $this->monorepoRoot;
    }

    private function resolvedConfig(): ConfigLoader
    {
        return $this->config ?? new ConfigLoader([]);
    }

    private function buildDiscovery(string $packagesPath, ConfigLoader $config): PackageDiscovery
    {
        return new PackageDiscovery(
            $packagesPath,
            $config->getString('default_branch', 'main'),
            $config->getStringArray('allowed_prefixes'),
            $config->getStringArray('excluded_packages')
        );
    }

    private function buildChangelogWriter(ConfigLoader $config): ChangelogWriter
    {
        $changelogConfig = $config->getArray('changelog');

        return new ChangelogWriter(
            header:     (string) ($changelogConfig['header']      ?? '# Changelog'),
            dateFormat: (string) ($changelogConfig['date_format'] ?? 'Y-m-d'),
            filename:   (string) ($changelogConfig['filename']    ?? 'CHANGELOG.md')
        );
    }
}
