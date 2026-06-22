<?php declare(strict_types=1);

namespace Webkernel\XMonorepo\Engine\Sync;

enum VersionSyncMode: string
{
    /** Every non-module package is bumped to one target version. */
    case Uniform = 'uniform';

    /** Keep each package version field; only align internal require constraints. */
    case LockCurrent = 'lock-current';
}
