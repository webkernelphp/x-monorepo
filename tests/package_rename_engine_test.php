<?php declare(strict_types=1);

require dirname(__DIR__, 3) . '/third_party/autoload.php';

use Webkernel\XMonorepo\Engine\Rename\PackageRenameEngine;
use Webkernel\XMonorepo\Engine\Sync\ComposerJsonWriter;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wk-rename-' . bin2hex(random_bytes(4));
$packages = $root . '/packages';

mkdir($packages . '/component-routing', 0777, true);
mkdir($packages . '/consumer', 0777, true);

file_put_contents($root . '/composer.json', json_encode([
    'name' => 'webkernel/webkernel',
    'require' => [],
], JSON_PRETTY_PRINT) . "\n");

file_put_contents($packages . '/component-routing/composer.json', json_encode([
    'name' => 'webkernel/component-routing',
    'version' => '0.12.0',
    'extra' => [
        'webkernel' => [
            'package_repo' => 'git@github.com:webkernelphp/component-routing.git',
            'prefix' => 'component-routing',
        ],
    ],
], JSON_PRETTY_PRINT) . "\n");

file_put_contents($packages . '/consumer/composer.json', json_encode([
    'name' => 'webkernel/consumer',
    'require' => [
        'webkernel/component-routing' => '0.12.0',
    ],
], JSON_PRETTY_PRINT) . "\n");

$engine = new PackageRenameEngine(
    packagesRootPath: $packages,
    projectRootPath: $root,
    writer: new ComposerJsonWriter(),
);

$plan = $engine->plan('component-routing', 'component-config-routing');

assert($plan->oldName === 'webkernel/component-routing');
assert($plan->newName === 'webkernel/component-config-routing');
assert($plan->hasChanges());

$fields = array_map(static fn ($change): string => $change->field, $plan->changes);
assert(in_array('name', $fields, true));
assert(in_array('replace', $fields, true));
assert(in_array('extra.webkernel.package_repo', $fields, true));
assert(in_array('extra.webkernel.prefix', $fields, true));
assert(in_array('require', $fields, true));
assert(in_array('directory', $fields, true));

$engine->apply($plan);

$renamedComposer = $packages . '/component-config-routing/composer.json';
$consumerComposer = $packages . '/consumer/composer.json';

assert(is_dir($packages . '/component-config-routing'));
assert(!is_dir($packages . '/component-routing'));

$renamed = json_decode((string) file_get_contents($renamedComposer), true, 512, JSON_THROW_ON_ERROR);
$consumer = json_decode((string) file_get_contents($consumerComposer), true, 512, JSON_THROW_ON_ERROR);

assert($renamed['name'] === 'webkernel/component-config-routing');
assert($renamed['replace']['webkernel/component-routing'] === '*');
assert($renamed['extra']['webkernel']['package_repo'] === 'git@github.com:webkernelphp/component-config-routing.git');
assert($renamed['extra']['webkernel']['prefix'] === 'component-config-routing');
assert($consumer['require']['webkernel/component-config-routing'] === '0.12.0');
assert(!isset($consumer['require']['webkernel/component-routing']));

array_map('unlink', [
    $renamedComposer,
    $consumerComposer,
    $root . '/composer.json',
]);
rmdir($packages . '/component-config-routing');
rmdir($packages . '/consumer');
rmdir($packages);
rmdir($root);

echo "package_rename_engine_test: OK\n";
