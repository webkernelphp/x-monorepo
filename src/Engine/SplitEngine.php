<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine;

use Webkernel\StdGit\Exceptions\ProcessException;
use Webkernel\StdGit\Repository\GitRepository;
use Webkernel\StdGit\StdGit;
use Webkernel\XMonorepo\Engine\Discovery\PackageDefinition;
use Webkernel\XMonorepo\Engine\State\PackageJobEntry;
use Webkernel\XMonorepo\Engine\State\StateManager;
use Webkernel\XMonorepo\Exceptions\SplitException;

final readonly class SplitEngine
{
    public function __construct(
        private StdGit          $git,
        private StateManager    $stateManager,
        private ChangelogWriter $changelogWriter,
        private string          $monorepoPath,
        private string          $gitName     = 'Webkernel Release Bot',
        private string          $gitEmail    = 'releases@webkernel.io',
        private string          $pushUrlMode = 'auto'
    ) {
        if (!in_array($this->pushUrlMode, ['auto', 'https', 'ssh'], true)) {
            throw new \InvalidArgumentException("Unsupported push URL mode '{$this->pushUrlMode}'.");
        }
    }

    public function remoteExists(PackageDefinition $package): bool
    {
        return $this->git->isRemoteUrlReadable($this->resolvePushUrl($package->getSplitRepoUrl()));
    }

    /**
     * @param  (callable(string $type, string $chunk): void)|null $output
     * @throws SplitException
     */
    public function split(
        PackageDefinition $package,
        string $tag,
        bool $writeChangelog = false,
        ?callable $output = null
    ): PackageJobEntry {
        $entry = $this->loadOrCreateEntry($package, $tag);

        if ($entry->getStatus()->value === 'completed') {
            return $entry;
        }

        if ($tag === '' && $this->isAlreadySyncedToCurrentHead($entry)) {
            return $this->stateManager->markCompleted($entry);
        }

        $entry = $this->stateManager->markInProgress($entry);
        $tempPath = $this->createTempDirectory($package);

        try {
            $repo = $this->cloneRemote($package, $tempPath);
            $this->configureIdentity($repo);

            // Base our local clone on the current state of the remote split repo.
            // This ensures we are not "en avance" (ahead) on the remote before we make our changes.
            // We fetch and reset hard to origin/branch so our upcoming commit will be on top of remote's tip.
            $this->baseOnRemote($repo, $package->getDefaultBranch());

            $this->replaceWorkingTree($repo, $package->getAbsolutePath());

            if ($writeChangelog && $tag !== '') {
                $commits = $this->collectMonorepoCommitsForPackage($package);
                $this->changelogWriter->write($tempPath, $tag, $commits);
            }

            $repo->addAllChanges();

            if ($repo->hasChanges()) {
                $repo->commit($tag !== '' ? "release: {$tag}" : 'x-monorepo: sync');
                $repo->pushCurrentBranch($output);
            }

            if ($tag !== '') {
                $this->createTagIfMissing($repo, $tag);
                $repo->pushToUrl(
                    $this->resolvePushUrl($package->getSplitRepoUrl()),
                    "refs/tags/{$tag}:refs/tags/{$tag}",
                    [],
                    $output
                );
            }

            if ($tag === '') {
                $entry = $this->stateManager->recordSyncedHead($entry, $this->currentMonorepoHead());
            }

            return $this->stateManager->markCompleted($entry);
        } catch (\Throwable $e) {
            $message = $this->formatThrowableMessage($e);
            $this->stateManager->markFailed($entry, $message);
            throw new SplitException("Split failed for '{$package->getName()}': {$message}", 0, $e);
        } finally {
            $this->removeDirectory($tempPath);
            $this->cleanupPackageRepository($package);
        }
    }

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

    private function cloneRemote(PackageDefinition $package, string $tempPath): GitRepository
    {
        // Always use shallow clone. We are going to overwrite the working tree anyway.
        // This dramatically speeds up clones for packages like standard-pix.
        $extra = ['--origin', 'origin', '--depth=1'];

        return $this->git->cloneRepository(
            $this->resolvePushUrl($package->getSplitRepoUrl()),
            $tempPath,
            $extra
        );
    }

    private function checkoutBranch(GitRepository $repo, string $branch): void
    {
        try {
            $repo->checkout($branch);
            return;
        } catch (ProcessException) {
            $repo->run('checkout', '-B', $branch);
        }
    }

    /**
     * Make sure our clone of the split repo starts exactly from the remote's current tip.
     * This way, when we modify the tree and commit, we are building on top of remote,
     * and a normal push will not overwrite any remote commits.
     */
    private function baseOnRemote(GitRepository $repo, string $branch): void
    {
        try {
            $repo->run('fetch', '--depth=1', 'origin', $branch);
            // Reset to exactly what remote has (so we are not ahead)
            $repo->run('reset', '--hard', 'origin/' . $branch);
        } catch (ProcessException) {
            // Branch may not exist on remote yet (first split), or fetch failed.
            // Fall back to creating the branch locally.
            $this->checkoutBranch($repo, $branch);
        }
    }

    private function replaceWorkingTree(GitRepository $targetRepo, string $sourcePackagePath): void
    {
        $targetPath = $targetRepo->getPath();

        $this->emptyDirectoryExceptGit($targetPath);

        $monoGitDir = rtrim($this->monorepoPath, '/\\') . '/.git';
        $rel = $this->pathRelativeToMonorepo($sourcePackagePath);
        $strip = ($rel === '' || $rel === '.') ? 0 : substr_count($rel, '/') + 1;

        $targetRepo->extractSubtreeFromGitDir($monoGitDir, 'HEAD', $rel, $strip);
    }

    private function emptyDirectoryExceptGit(string $path): void
    {
        // Use native rm via shell for speed (thousands of files in packages like standard-pix)
        // instead of slow PHP recursion.
        $cmd = sprintf('find %s -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +', escapeshellarg($path));

        $process = proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);

        if (is_resource($process)) {
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        } else {
            // Fallback to PHP if shell fails
            foreach (new \DirectoryIterator($path) as $item) {
                if ($item->isDot()) continue;
                if ($item->getFilename() === '.git') continue;
                $item->isDir() && !$item->isLink() ? $this->removeDirectory($item->getPathname()) : unlink($item->getPathname());
            }
        }
    }



    private function createTempDirectory(PackageDefinition $package): string
    {
        $safeName = preg_replace('#[^a-z0-9_.-]+#i', '-', $package->getName()) ?: 'package';
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'x-monorepo-' . $safeName . '-' . bin2hex(random_bytes(4));

        if (!mkdir($path, 0777, true)) {
            throw new SplitException("Cannot create temporary split directory '{$path}'.");
        }

        return $path;
    }

    private function isAlreadySyncedToCurrentHead(PackageJobEntry $entry): bool
    {
        return ($entry->getOperationState()->getPayload()['synced_head'] ?? null) === $this->currentMonorepoHead();
    }

    private function currentMonorepoHead(): string
    {
        return $this->git->open($this->monorepoPath)->getLastCommitId()->toString();
    }

    /**
     * @return \Webkernel\StdGit\Objects\Commit[]
     */
    private function collectMonorepoCommitsForPackage(PackageDefinition $package): array
    {
        $monorepoRepo = $this->git->open($this->monorepoPath);

        try {
            $logResult = $monorepoRepo->runArgs([
                'log', 'HEAD', '--pretty=format:%H', '--', $this->pathRelativeToMonorepo($package->getAbsolutePath()),
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
            }
        }

        return $commits;
    }

    private function pathRelativeToMonorepo(string $path): string
    {
        $root = rtrim(realpath($this->monorepoPath) ?: $this->monorepoPath, '/\\') . DIRECTORY_SEPARATOR;
        $absolute = realpath($path) ?: $path;

        return str_starts_with($absolute, $root)
            ? substr($absolute, strlen($root))
            : $path;
    }

    private function configureIdentity(GitRepository $repository): void
    {
        $repository->run('config', '--local', 'user.name', $this->gitName);
        $repository->run('config', '--local', 'user.email', $this->gitEmail);
    }

    private function createTagIfMissing(GitRepository $repository, string $tag): void
    {
        $existing = array_map(static fn (\Webkernel\StdGit\Refs\Tag $t): string => $t->getName(), $repository->getTags());

        if (!in_array($tag, $existing, true)) {
            $repository->createTag($tag);
        }
    }

    private function loadOrCreateEntry(PackageDefinition $package, string $tag): PackageJobEntry
    {
        $currentHead = $this->currentMonorepoHead();
        $jobTag = $tag !== '' ? $tag : 'snapshot-' . substr($currentHead, 0, 12);
        $existing = $this->stateManager->load($package->getName(), $jobTag);

        if ($existing instanceof \Webkernel\XMonorepo\Engine\State\PackageJobEntry) {
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
        if (!$e instanceof ProcessException || !$e->getRunnerResult() instanceof \Webkernel\StdGit\Processors\RunnerResult) {
            return $e->getMessage();
        }

        $stderr = $e->getRunnerResult()->getStderrAsString();

        return $stderr === '' ? $e->getMessage() : $e->getMessage() . "\n" . $stderr;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

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
