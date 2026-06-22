<?php declare(strict_types=1);

require dirname(__DIR__, 3) . '/third_party/autoload.php';

use Webkernel\XMonorepo\Engine\Sync\ComposerJsonWriter;
use Webkernel\XMonorepo\Engine\Sync\ConstraintOperator;
use Webkernel\XMonorepo\Engine\Sync\InternalPackageCatalog;
use Webkernel\XMonorepo\Engine\Sync\PackageModuleClassifier;
use Webkernel\XMonorepo\Engine\Sync\VersionConstraintResolver;
use Webkernel\XMonorepo\Engine\Sync\VersionSyncEngine;
use Webkernel\XMonorepo\Engine\Sync\VersionSyncMode;
use Webkernel\XMonorepo\Engine\Sync\VersionSyncOptions;

$root = dirname(__DIR__, 3);
$packages = $root . '/packages';

$resolver = new VersionConstraintResolver();

assert($resolver->buildConstraint('0.12.0', ConstraintOperator::Floor) === '>=0.12.0');
assert($resolver->buildConstraint('0.12.0', ConstraintOperator::Caret) === '^0.12.0');
assert($resolver->buildConstraint('0.12.0', ConstraintOperator::Exact) === '0.12.0');
assert($resolver->bumpType('0.11.4', '0.12.0') === 'minor');
assert($resolver->bumpType('0.11.4', '1.0.0') === 'major');
assert($resolver->satisfies('0.12.0', '^0.12.0') === true);
assert($resolver->shouldAlignConstraint('>=0.11.4', '>=0.12.0') === true);
assert($resolver->mustRewriteConstraint('^0.1.0', '>=1.0.0', '1.0.0') === true);

$engine = new VersionSyncEngine(
    packagesRootPath: $packages,
    projectRootPath: $root,
    catalog: new InternalPackageCatalog($packages, new PackageModuleClassifier()),
    constraints: $resolver,
    writer: new ComposerJsonWriter(),
);

$uniform = $engine->plan(new VersionSyncOptions(
    mode: VersionSyncMode::Uniform,
    constraintOperator: ConstraintOperator::Floor,
    targetVersion: '0.12.0',
    dryRun: true,
));

assert($uniform->mode === VersionSyncMode::Uniform);
assert($uniform->hasChanges());

foreach ($uniform->syncedVersions as $name => $version) {
    if (in_array($name, [
        'numerimondes/numerimondes-cloud-app',
        'webkernel/module-webkernelphp-com',
        'webkernel/x-example-module',
    ], true)) {
        assert($version === '0.1.0');
        continue;
    }

    assert($version === '0.12.0');
}

$lock = $engine->plan(new VersionSyncOptions(
    mode: VersionSyncMode::LockCurrent,
    constraintOperator: ConstraintOperator::Caret,
    dryRun: true,
));

assert($lock->mode === VersionSyncMode::LockCurrent);

$versionChanges = array_filter(
    $lock->changes,
    static fn ($change): bool => $change->field === 'version'
);
assert($versionChanges === []);

foreach ($lock->changes as $change) {
    if ($change->dependency === null) {
        continue;
    }

    if (in_array($change->dependency, [
        'numerimondes/numerimondes-cloud-app',
        'webkernel/module-webkernelphp-com',
        'webkernel/x-example-module',
    ], true) && str_contains($change->file, '/packages/')) {
        throw new RuntimeException("Module dependency should not be updated in package composer: {$change->file}");
    }
}

try {
    $engine->plan(new VersionSyncOptions(
        mode: VersionSyncMode::Uniform,
        constraintOperator: ConstraintOperator::Floor,
        targetVersion: '1.0.0',
        syncModuleVersions: true,
        dryRun: true,
    ));
    throw new RuntimeException('Expected major module bump validation to fail without --update-root-module-requires.');
} catch (Webkernel\XMonorepo\Exceptions\XMonorepoException) {
    // Root ^0.1.0 cannot satisfy synced module 1.0.0.
}

echo "version_sync_engine_test.php: OK\n";
