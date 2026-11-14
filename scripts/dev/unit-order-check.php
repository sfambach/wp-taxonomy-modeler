<?php declare(strict_types=1);
/**
 * D-1359: the «add» list of allowed units offers the base units alphabetically — they come from two groups («With prefix»,
 * «Without prefix»), and the tree's order is per group. Prefixes, children of one parent, keep the model's order.
 *
 *     WP_ROOT=/path/to/wordpress php scripts/dev/unit-order-check.php
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

use Taxmod\WordPress\Plugin;
use Taxmod\WordPress\Persistence\UnitScaffold;

wp_set_current_user((int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 0));

global $wpdb;
// A unit value node: the node a field «einheit» to «Base units» starts from.
$base     = (int) (UnitScaffold::nodeId(UnitScaffold::BASE_UNITS_NAME) ?? 0);
$prefixes = (int) (UnitScaffold::nodeId(UnitScaffold::PREFIXES_NAME) ?? 0);
$value    = (int) $wpdb->get_var($wpdb->prepare("SELECT from_node_id FROM {$wpdb->prefix}taxmod_relations WHERE to_node_id = %d AND from_node_id <> %d LIMIT 1", $base, $base));

if ($base === 0 || $value === 0) {
    echo "SKIP no unit value node\n";
    exit(0);
}

$_GET['page']        = 'taxmod-nodes';
$_GET['taxmod_node'] = (string) $value;
$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$page = $plugin->screen()->render();

$offered = static function (string $page, string $setting): array {
    if (preg_match('/<select name="taxmod_setting_set\[' . $setting . '\]\[add\]"[^>]*>(.*?)<\/select>/s', $page, $m) !== 1) {
        return [];
    }

    preg_match_all('/<option value="(\d+)"[^>]*>([^<]*)<\/option>/', $m[1], $o);

    return array_combine($o[1], array_map('html_entity_decode', $o[2]));
};

$units  = $offered($page, 'erlaubte_einheiten');
$sorted = $units;
uasort($sorted, static fn (string $a, string $b): int => strnatcasecmp($a, $b));

$groups = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(DISTINCT u.parent_node_id) FROM {$wpdb->prefix}taxmod_nodes u JOIN {$wpdb->prefix}taxmod_nodes g ON g.id = u.parent_node_id WHERE g.parent_node_id = %d AND u.hide = 0",
    $base
));

$claims = [
    'the unit list is offered (' . count($units) . ' units)'      => count($units) > 1,
    'the units come from more than one group'                     => $groups > 1,
    'they are offered alphabetically'                             => array_keys($units) === array_keys($sorted),
];
$bad = 0;

foreach ($claims as $claim => $holds) {
    echo ($holds ? 'ok   ' : 'FAIL ') . $claim . "\n";
    $bad += $holds ? 0 : 1;
}

if (array_keys($units) !== array_keys($sorted)) {
    echo '     got: ' . implode(', ', $units) . "\n";
}

exit($bad === 0 ? 0 : 1);
