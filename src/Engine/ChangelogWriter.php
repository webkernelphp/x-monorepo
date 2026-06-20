<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine;

use Webkernel\StdGit\Objects\Commit;
use Webkernel\XMonorepo\Exceptions\XMonorepoException;

/**
 * Writes or prepends a changelog entry to a CHANGELOG.md file.
 */
final readonly class ChangelogWriter
{
    public function __construct(
        private string $header     = '# Changelog',
        private string $dateFormat = 'Y-m-d',
        private string $filename   = 'CHANGELOG.md'
    ) {}

    /**
     * Prepend a release entry to the changelog in the given directory.
     *
     * Skipped entirely when $tag is empty — there is no meaningful version
     * anchor to write in snapshot (no-tag) mode.
     *
     * @param  string    $packagePath  Absolute path to the package root.
     * @param  string    $tag          Version tag (e.g. "1.2.3"). Must not be empty.
     * @param  Commit[]  $commits      Commits included in this release (most-recent first).
     * @param  \DateTimeImmutable|null $date  Release date; defaults to today.
     * @throws XMonorepoException
     */
    public function write(
        string $packagePath,
        string $tag,
        array $commits,
        ?\DateTimeImmutable $date = null
    ): void {
        if ($tag === '') {
            // No version anchor — nothing useful to write.
            return;
        }

        $changelogPath = rtrim($packagePath, '/\\') . DIRECTORY_SEPARATOR . $this->filename;
        $existing      = file_exists($changelogPath) ? (string) file_get_contents($changelogPath) : '';

        // Strip the static header if already present so we can re-prepend it cleanly.
        if (str_starts_with(ltrim($existing), $this->header)) {
            $existing = ltrim(substr(ltrim($existing), strlen($this->header)));
        }

        $releaseDate = ($date ?? new \DateTimeImmutable())->format($this->dateFormat);
        $entry       = $this->buildEntry($tag, $releaseDate, $commits);
        $content     = $this->header . "\n\n" . $entry . "\n" . ltrim($existing);

        if (file_put_contents($changelogPath, $content) === false) {
            throw new XMonorepoException("Cannot write changelog to '$changelogPath'.");
        }
    }

    /**
     * @param Commit[] $commits
     */
    private function buildEntry(string $tag, string $date, array $commits): string
    {
        $lines = ["## [{$tag}] - {$date}", ''];

        if ($commits === []) {
            $lines[] = '- No changes recorded.';
        } else {
            foreach ($commits as $commit) {
                $short   = substr($commit->getId()->toString(), 0, 8);
                $subject = $commit->getSubject();
                $lines[] = "- {$subject} ({$short})";
            }
        }

        $lines[] = '';

        return implode("\n", $lines);
    }
}
