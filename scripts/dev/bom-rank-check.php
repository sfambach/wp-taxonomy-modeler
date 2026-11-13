<?php declare(strict_types=1);
/**
 * Bills of materials are ordered by the «Rang in Stücklisten» group property of each line's material (D-1341).
 *
 *     WP_ROOT=/path/to/wordpress php scripts/dev/bom-rank-check.php
 *
 * @see docs/NewConcept/90-decision-log.md D-1341
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
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\ModelDoor;
use Taxmod\WordPress\Plugin;

$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $bad;
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . ($passed || $detail === '' ? '' : " — $detail") . "\n";
    $bad += $passed ? 0 : 1;
}

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 1));

$plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($plugin, 'file'))->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$model = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin)->rootOf(Branch::Model);

check('the modeller declares the rank as a number', (ModelDoor::properties()[Plugin::BOM_RANK]['type'] ?? '') === 'number', wp_json_encode(ModelDoor::properties()));

$built = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'caps', 'name' => '__br Kondensatoren', 'parent' => $model->id],
    ['op' => 'create_node', 'ref' => 'cap', 'name' => '__br 100n', 'parent' => '@caps'],
    ['op' => 'create_node', 'ref' => 'res', 'name' => '__br Widerstände', 'parent' => $model->id],
    ['op' => 'create_node', 'ref' => 'misc', 'name' => '__br Rest', 'parent' => $model->id],
    ['op' => 'create_record', 'ref' => 'c', 'node' => '@cap'],
    ['op' => 'create_record', 'ref' => 'r', 'node' => '@res'],
    ['op' => 'create_record', 'ref' => 'm', 'node' => '@misc'],
]]);

check('the test nodes are built', is_array($built), is_wp_error($built) ? $built->get_error_message() : '');

if (! is_array($built)) {
    exit(1);
}

$ref  = $built['refs'];
$door = new ModelDoor();
$door->setProperty($ref['res'], Plugin::BOM_RANK, 1);
$door->setProperty($ref['caps'], Plugin::BOM_RANK, 2);

$part  = static fn (int $id, ?int $points): array => ['id' => $id, 'nodeId' => 0, 'werte' => $points === null ? [] : [7 => TypedValue::ofRecordReference($points)], 'teile' => []];
$parts = [5 => [$part(1, $ref['m']), $part(2, $ref['c']), $part(3, null), $part(4, $ref['r']), $part(5, $ref['m'])]];
$order = array_column(NodesScreen::rankedParts($parts)[5], 'id');

check('ranked lines first, by rank, inherited from the group', array_slice($order, 0, 2) === [4, 2], wp_json_encode($order));
check('lines without a rank keep their stored order behind', array_slice($order, 2) === [1, 3, 5], wp_json_encode($order));
check('without any reference nothing moves', array_column(NodesScreen::rankedParts([5 => [$part(9, null), $part(8, null)]])[5], 'id') === [9, 8]);

echo $bad === 0 ? "\nall green\n" : "\n$bad failed\n";
exit($bad === 0 ? 0 : 1);
