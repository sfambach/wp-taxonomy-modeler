<?php declare(strict_types=1);
/**
 * A module declares a group property, sets it at a node through the door and reads it inherited below (D-1259).
 *
 *     WP_ROOT=/path/to/wordpress php scripts/dev/node-property-check.php
 *
 * @see docs/gestaltung-und-architektur.md A9
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: '';

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Pass the WordPress folder as the first argument or WP_ROOT.\n");
    exit(2);
}

define('WP_USE_THEMES', false);

require $root . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\WordPress\ModelDoor;
use Taxmod\WordPress\Plugin;

$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $bad;
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . ($passed || $detail === '' ? '' : " — $detail") . "\n";
    $bad += $passed ? 0 : 1;
}

add_filter(Plugin::NODE_PROPERTIES_FILTER, static function (array $declared): array {
    $declared['np_test_after_inbound'] = 'Test after goods-in';
    $declared['np_default_place']      = ['label' => 'Default storage place', 'type' => 'id'];

    return $declared;
});

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 1));

$plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($plugin, 'file'))->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$model = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin)->rootOf(Branch::Model);

$built = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'm', 'name' => '__np Material', 'parent' => $model->id],
    ['op' => 'create_node', 'ref' => 'b', 'name' => '__np Bauteile', 'parent' => '@m'],
    ['op' => 'create_node', 'ref' => 'k', 'name' => '__np Kabel', 'parent' => '@b'],
]]);

check('the test nodes are built', is_array($built), is_wp_error($built) ? $built->get_error_message() : '');

if (! is_array($built)) {
    exit(1);
}

$ref  = $built['refs'];
$door = new ModelDoor();
$all  = [$ref['m'], $ref['b'], $ref['k']];

check('the declared properties are listed with their type', (ModelDoor::properties()['np_default_place']['type'] ?? '') === 'id' && (ModelDoor::properties()['np_test_after_inbound']['type'] ?? '') === 'bool', wp_json_encode(ModelDoor::properties()));
check('nothing set, nothing holds', $door->propertiesOf($all, 'np_test_after_inbound') === [$ref['m'] => null, $ref['b'] => null, $ref['k'] => null]);

$door->setProperty($ref['m'], 'np_test_after_inbound', true);
$door->setProperty($ref['k'], 'np_test_after_inbound', false);
$door->setProperty($ref['b'], 'np_default_place', 4711);

$test  = $door->propertiesOf($all, 'np_test_after_inbound');
$place = $door->propertiesOf($all, 'np_default_place');
check('set at the group, it holds below', $test[$ref['m']] === true && $test[$ref['b']] === true, wp_json_encode($test));
check('a node below can say otherwise', $test[$ref['k']] === false, wp_json_encode($test));
check('an id is inherited as a number', $place === [$ref['m'] => null, $ref['b'] => 4711, $ref['k'] => 4711], wp_json_encode($place));

$door->setProperty($ref['k'], 'np_test_after_inbound', null);
check('null drops the own value, the group holds again', $door->propertiesOf([$ref['k']], 'np_test_after_inbound') === [$ref['k'] => true]);

$queries = $GLOBALS['wpdb']->num_queries;
$door->propertiesOf($all, 'np_test_after_inbound');
check('reading many nodes costs two queries (CD-7)', $GLOBALS['wpdb']->num_queries - $queries <= 2, (string) ($GLOBALS['wpdb']->num_queries - $queries));

$refused = '';

try {
    $door->setProperty($ref['m'], 'np_not_declared', true);
} catch (\Taxmod\Core\Exception\ModelDoorRefused $e) {
    $refused = $e->getMessage();
}

check('an undeclared property is refused', $refused !== '', $refused);

wp_set_current_user(0);
$refused = '';

try {
    $door->setProperty($ref['m'], 'np_test_after_inbound', false);
} catch (\Taxmod\Core\Exception\ModelDoorRefused $e) {
    $refused = $e->getMessage();
}

check('without the capability nothing is written', $refused !== '', $refused);

echo $bad === 0 ? "\nall green\n" : "\n$bad failed\n";
exit($bad === 0 ? 0 : 1);
