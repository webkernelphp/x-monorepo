<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine\Sync;

enum ConstraintOperator: string
{
    case Floor  = '>=';
    case Caret  = '^';
    case Exact  = '=';

    public function label(): string
    {
        return match ($this) {
            self::Floor => '>= (floor — allows newer patches/minors within range)',
            self::Caret => '^ (caret — semver-compatible range)',
            self::Exact => '= (exact pin)',
        };
    }

    public function example(string $version): string
    {
        return (new VersionConstraintResolver())->buildConstraint($version, $this);
    }
}
