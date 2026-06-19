<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine;

use Webkernel\StdGit\Repository\GitRepository;
use Webkernel\StdGit\Exceptions\ProcessException;
use Webkernel\StdGit\StdGit;
use Webkernel\XMonorepo\Engine\Discovery\PackageDefinition;
use Webkernel\XMonorepo\Engine\State\PackageJobEntry;
use Webkernel\XMonorepo\Engine\State\StateManager;
use Webkernel\XMonorepo\Exceptions\SplitException;

/**
 * Orchestrates the split/sync workflow for a single package repository.
 *
 * Design contract
 * ───────────────
 * The split is a SYNC operation. Each run snapshots the current package
 * directory into a fresh, single-commit repo and force-pushes it to the
 * split remote. There is intentionally no shared history with the monorepo.
 *
 * Package .git directories are ephemeral — they are wiped before every run
 * and cleaned up after. The remote is the canonical history store. This
 * prevents the monorepo from treating packages as nested repositories.
 *
 * Idempotency
 * ───────────
 * Tagged mode   → tag is the key. If state shows "completed" for pkg+tag, skip.
 * Snapshot mode → monorepo HEAD is the key. If state records that we already
 *                 pushed this exact HEAD for this package, skip.
 */
final class SplitEngine
{
    public function __construct(
        private readonly StdGit          $git,
        private readonly StateManager    $stateManager,
        private readonly ChangelogWriter $changelogWriter,
        private readonly string          $monorepoPath,
        private readonly string          $pushSafetyUrl = 'git@disabled.invalid:disabled/disabled.git',
        private readonly bool            $makeReadOnly  = true,
        private readonly string          $gitName       = 'Webkernel Release Bot',
        private readonly string          $gitEmail      = 'releases@webkernel.io',
        private readonly string          $pushUrlMode   = 'auto'
    ) {
        if (!in_array($this->pushUrlMode, ['auto', 'https', 'ssh'], true)) {
            throw new \InvalidArgumentException("Unsupported push URL mode '{$this->pushUrlMode}'.");
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Public phase API
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Phase 1 — Wipe any leftover .git, init a fresh repo, stage everything,
     * optionally write a changelog, then always commit (--allow-empty so HEAD
     * is guaranteed to exist even when no files changed).
     *
     * Returns early (as "completed") in snapshot mode when the monorepo HEAD
     * has not changed since the last successful push for this package.
     *
     * @throws SplitException
     */
    public function prepare(PackageDefinition $package, string $tag): PackageJobEntry
    {
        $entry = $this->loadOrCreateEntry($package, $tag);

        if ($entry->getStatus()->value === 'completed') {
            return $entry;
        }

        // Snapshot idempotency: nothing changed since last push → nothing to do.
        if ($tag === '' && $this->isAlreadySyncedToCurrentHead($entry)) {
            return $this->stateManager->markCompleted($entry);
        }

        $entry = $this->stateManager->markInProgress($entry);

        try {
            // Wipe any leftover ephemeral .git from a previous run before re-init.
            // This guarantees we always start from a clean single-commit repo.
            $this->cleanupPackageRepository($package);

            $packageRepo = $this->git->init($package->getAbsolutePath());
            $this->configureIdentity($packageRepo);

            // Stage the full current state of the package directory.
            $packageRepo->addAllChanges();

            // Changelog: only when a real version tag is provided.
            if ($tag !== '') {
                $commits = $this->collectMonorepoCommitsForPackage($package, $tag);
                $this->changelogWriter->write($package->getAbsolutePath(), $tag, $commits);
                // Stage the freshly written changelog file.
                $packageRepo->addAllChanges();
            }

            // --allow-empty guarantees HEAD exists even when nothing is staged
            // (e.g. snapshot resync with no file changes). Without this the
            // subsequent push() fails with "src refspec HEAD does not match any".
            $label = $tag !== '' ? "release: {$tag}" : 'x-monorepo: sync';
            $packageRepo->run('commit', '--allow-empty', ['-m' => $label]);

            return $entry; // still in-progress; push() advances it
        } catch (\Throwable $e) {
            $message = $this->formatThrowableMessage($e);
            $this->stateManager->markFailed($entry, $message);
            throw new SplitException(
                "Prepare failed for '{$package->getName()}': {$message}", 0, $e
            );
        }
    }

    /**
     * Phase 2 — Force-push the package repo's HEAD to the split remote.
     *
     * @param  (callable(string $type, string $chunk): void)|null $output
     * @throws SplitException
     */
    public function push(PackageDefinition $package, string $tag, ?callable $output = null): PackageJobEntry
    {
        $entry = $this->loadOrCreateEntry($package, $tag);

        // Already marked completed by prepare() (snapshot no-op).
        if ($entry->getStatus()->value === 'completed') {
            return $entry;
        }

        try {
            $branch   = $package->getDefaultBranch();
            $splitUrl = $package->getSplitRepoUrl();
            $pushUrl  = $this->resolvePushUrl($splitUrl);

            $packageRepo = $this->git->open($package->getAbsolutePath());
            $this->configureIdentity($packageRepo);

            if ($this->makeReadOnly) {
                $packageRepo->configureRemote($splitUrl, $this->pushSafetyUrl, 'origin');
            }

            $packageRepo->pushToUrl(
                $pushUrl,
                "HEAD:refs/heads/{$branch}",
                ['--force'],
                $output
            );

            // Record the monorepo HEAD we just pushed for snapshot idempotency.
            if ($tag === '') {
                $this->stateManager->recordSyncedHead(
                    $entry,
                    $this->currentMonorepoHead()
                );
            }

            return $entry;
        } catch (\Throwable $e) {
            $message = $this->formatThrowableMessage($e);
            $this->stateManager->markFailed($entry, $message);
            throw new SplitException(
                "Push failed for '{$package->getName()}': {$message}", 0, $e
            );
        }
    }

    /**
     * Phase 3 — Create and push the version tag. Marks the job completed.
     *
     * @throws SplitException
     */
    public function tag(PackageDefinition $package, string $tag): PackageJobEntry
    {
        $entry = $this->loadOrCreateEntry($package, $tag);

        try {
            $pushUrl     = $this->resolvePushUrl($package->getSplitRepoUrl());
            $packageRepo = $this->git->open($package->getAbsolutePath());

            $this->createTagIfMissing($packageRepo, $tag);

            $packageRepo->pushToUrl(
                $pushUrl,
                "refs/tags/{$tag}:refs/tags/{$tag}",
                ['--force']
            );

            return $this->stateManager->markCompleted($entry);
        } catch (\Throwable $e) {
            $message = $this->formatThrowableMessage($e);
            $this->stateManager->markFailed($entry, $message);
            throw new SplitException(
                "Tag failed for '{$package->getName()}': {$message}", 0, $e
            );
        }
    }

    /**
     * Mark a package completed without tagging (used when $tag === '').
     */
    public function markCompleted(PackageDefinition $package, string $tag): PackageJobEntry
    {
        $entry = $this->loadOrCreateEntry($package, $tag);
        return $this->stateManager->markCompleted($entry);
    }

    /**
     * Remove the package's ephemeral .git directory.
     *
     * Always safe to call: silently returns if .git does not exist.
     * Called at the start of prepare() (clean slate) and at the end of the
     * full split run (prevent monorepo from seeing nested repos).
     */
    public function cleanupPackageRepository(PackageDefinition $package): void
    {
        $gitPath = rtrim($package->getAbsolutePath(), '/\\') . DIRECTORY_SEPARATOR . '.git';

        if (is_file($gitPath) || is_link($gitPath)) {
            unlink($gitPath);
            return;
        }

        if (is_dir($gitPath)) {
            $this->removeDirectory($gitPath);
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Snapshot idempotency
    // ═══════════════════════════════════════════════════════════════════════

    private function isAlreadySyncedToCurrentHead(PackageJobEntry $entry): bool
    {
        $syncedHead = $entry->getOperationState()->getPayload()['synced_head'] ?? null;
        return $syncedHead === $this->currentMonorepoHead();
    }

    private function currentMonorepoHead(): string
    {
        return $this->git->open($this->monorepoPath)->getLastCommitId()->toString();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Changelog helpers
    // ═══════════════════════════════════════════════════════════════════════

    /**
     * Collect monorepo commits that touched this package's subdirectory,
     * scoped between the previous tag and HEAD (or all history if first release).
     *
     * @return \Webkernel\StdGit\Objects\Commit[]
     */
    private function collectMonorepoCommitsForPackage(
        PackageDefinition $package,
        string $tag
    ): array {
        $monorepoRepo   = $this->git->open($this->monorepoPath);
        $packageRelPath = $package->getRelativePath();
        $rangeStart     = $this->findPreviousTagOnMonorepo($monorepoRepo);
        $range          = $rangeStart !== null ? "{$rangeStart}..HEAD" : 'HEAD';

        try {
            $logResult = $monorepoRepo->runArgs([
                'log', $range, '--pretty=format:%H', '--', $packageRelPath,
            ]);
        } catch (ProcessException) {
            return [];
        }

        $commits = [];

        foreach ($logResult->getStdout() as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            try {
                $commits[] = $monorepoRepo->getCommit($line);
            } catch (\Throwable) {
                // Skip unparseable commits rather than aborting.
            }
        }

        return $commits;
    }

    private function findPreviousTagOnMonorepo(GitRepository $monorepoRepo): ?string
    {
        try {
            $result = $monorepoRepo->runArgs(['describe', '--tags', '--abbrev=0', 'HEAD^']);
            $prev   = trim($result->getStdoutAsString());
            return $prev !== '' ? $prev : null;
        } catch (ProcessException) {
            return null; // first release or no previous tag
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Internal helpers
    // ═══════════════════════════════════════════════════════════════════════

    private function configureIdentity(GitRepository $repository): void
    {
        $repository->run('config', '--local', 'user.name',  $this->gitName);
        $repository->run('config', '--local', 'user.email', $this->gitEmail);
    }

    private function createTagIfMissing(GitRepository $repository, string $tag): void
    {
        $existing = array_map(static fn ($t) => $t->getName(), $repository->getTags());

        if (!in_array($tag, $existing, true)) {
            $repository->createTag($tag);
        }
    }

    private function loadOrCreateEntry(PackageDefinition $package, string $tag): PackageJobEntry
    {
        $monorepoRepo = $this->git->open($this->monorepoPath);
        $currentHead  = $monorepoRepo->getLastCommitId()->toString();

        // In snapshot mode the job key is per-HEAD so each new monorepo commit
        // gets its own state entry and old ones are preserved for auditing.
        $jobTag = $tag !== ''
            ? $tag
            : 'snapshot-' . substr($currentHead, 0, 12);

        $existing = $this->stateManager->load($package->getName(), $jobTag);

        if ($existing !== null) {
            return $existing;
        }

        $entry = PackageJobEntry::create(
            $package->getName(),
            $package->getSplitRepoUrl(),
            $package->getDefaultBranch(),
            $jobTag
        );

        $this->stateManager->initJob($entry);

        return $entry;
    }

    private function resolvePushUrl(string $url): string
    {
        if ($this->pushUrlMode === 'ssh') {
            return $url;
        }

        $token = $this->githubToken();

        if (!preg_match('#^git@github\.com:(.+)$#', $url, $matches)) {
            return $url;
        }

        $path = $matches[1];

        if ($token !== null) {
            return 'https://x-access-token:' . rawurlencode($token) . '@github.com/' . $path;
        }

        if ($this->pushUrlMode === 'https' || $this->monorepoUsesHttpsRemote()) {
            return 'https://github.com/' . $path;
        }

        return $url;
    }

    private function monorepoUsesHttpsRemote(): bool
    {
        $repository = $this->git->open($this->monorepoPath);

        foreach ([['remote', 'get-url', '--push', 'origin'], ['remote', 'get-url', 'origin']] as $command) {
            try {
                $url = trim($repository->runArgs($command)->getStdoutAsString());
                if ($url !== '') {
                    return str_starts_with($url, 'https://');
                }
            } catch (ProcessException) {
                continue;
            }
        }

        return false;
    }

    private function githubToken(): ?string
    {
        foreach (['GITHUB_TOKEN', 'GH_TOKEN'] as $name) {
            $token = getenv($name);
            if (is_string($token) && trim($token) !== '') {
                return trim($token);
            }
        }

        $process = proc_open(
            ['gh', 'auth', 'token'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $token = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && $token !== '' ? $token : null;
    }

    private function formatThrowableMessage(\Throwable $e): string
    {
        if (!$e instanceof ProcessException || $e->getRunnerResult() === null) {
            return $e->getMessage();
        }

        $stderr = $e->getRunnerResult()->getStderrAsString();

        return $stderr === '' ? $e->getMessage() : $e->getMessage() . "\n" . $stderr;
    }

    private function removeDirectory(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink()
                ? rmdir($item->getPathname())
                : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
