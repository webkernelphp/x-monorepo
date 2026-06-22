<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine\Sync;

use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Webkernel\XMonorepo\Exceptions\XMonorepoException;

/**
 * Normalizes versions and builds internal monorepo constraints via composer/semver.
 */
final readonly class VersionConstraintResolver
{
    private VersionParser $parser;

    public function __construct(?VersionParser $parser = null)
    {
        $this->parser = $parser ?? new VersionParser();
    }

    public function assertValidVersion(string $version): string
    {
        try {
            return $this->parser->normalize($version);
        } catch (\UnexpectedValueException $e) {
            throw new XMonorepoException("Invalid version '{$version}': {$e->getMessage()}", 0, $e);
        }
    }

    public function prettyVersion(string $version): string
    {
        $normalized = $this->assertValidVersion($version);

        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $normalized, $matches)) {
            throw new XMonorepoException("Cannot parse version '{$version}'.");
        }

        return sprintf('%d.%d.%d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
    }

    public function buildConstraint(string $version, ConstraintOperator $operator): string
    {
        $pretty = $this->prettyVersion($version);

        return match ($operator) {
            ConstraintOperator::Floor => ">={$pretty}",
            ConstraintOperator::Caret  => "^{$pretty}",
            ConstraintOperator::Exact  => $pretty,
        };
    }

    /**
     * @return 'major'|'minor'|'patch'|'none'
     */
    public function bumpType(?string $fromVersion, string $toVersion): string
    {
        if ($fromVersion === null || trim($fromVersion) === '') {
            return 'none';
        }

        try {
            $from = $this->parser->normalize($fromVersion);
            $to   = $this->assertValidVersion($toVersion);
        } catch (XMonorepoException) {
            return 'none';
        }

        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $from, $fromParts)
            || !preg_match('/^(\d+)\.(\d+)\.(\d+)/', $to, $toParts)) {
            return 'none';
        }

        if ($fromParts[1] !== $toParts[1]) {
            return 'major';
        }

        if ($fromParts[2] !== $toParts[2]) {
            return 'minor';
        }

        if ($fromParts[3] !== $toParts[3]) {
            return 'patch';
        }

        return 'none';
    }

    public function satisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($this->parser->normalize($version), $constraint);
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    public function shouldAlignConstraint(string $currentConstraint, string $targetConstraint): bool
    {
        return trim($currentConstraint) !== $targetConstraint;
    }

    /**
     * Used for root module requires: only rewrite when the synced version would not satisfy.
     */
    public function mustRewriteConstraint(string $currentConstraint, string $targetConstraint, string $targetVersion): bool
    {
        if ($this->shouldAlignConstraint($currentConstraint, $targetConstraint)) {
            return true;
        }

        if (in_array(trim($currentConstraint), ['*', '*@dev'], true)) {
            return true;
        }

        return !$this->satisfies($targetVersion, $currentConstraint);
    }
}
