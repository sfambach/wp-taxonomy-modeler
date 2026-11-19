<?php declare(strict_types=1);
/**
 * A module reads a node with only the fields it names and gets what the whole read gives for them — in one query (D-1486).
 *
 *     php scripts/dev/model-door-fields-check.php [path/to/wordpress]
 *
 * ⚠️ *Measured 2026-11-19 with purchases as wide as the live ones (some eleven values and three price parts each): a tab of the
 * stock's goods receipt that read every value of every record peaked at 145–157 MB over the base, read narrow at 75–86 MB.
 * His words 18.11. 17:57: «Angekommen ist auch nicht wirklich sauschnell … nur noch mehrere Sekunden».*
 *
 * ```mermaid
 * flowchart LR
 *   M["module: node + fields"] --> D["ModelDoor::recordsOfFields"]
 *   D -->|"read-node, fields"| A["one query: values of the node in those fields"]
 *   D --> S["same values as records() for those fields"]
 * ```
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: null;

if ($root === null) {
    $dir = getcwd();
    while ($dir !== '' && ! is_readable($dir . '/wp-load.php')) {
        $up  = dirname($dir);
        $dir = $up === $dir ? '' : $up;
    }
    $root = $dir;
}

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php. Pass the WordPress folder as the first argument.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';

require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\ModelDoor;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;

global $wpdb;
$ok  = 0;
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $ok, $bad;

    if ($passed) {
        $ok++;
        echo "  OK   $what\n";

        return;
    }

    $bad++;
    echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n";
}

if (! function_exists('wp_get_ability')) {
    echo "  SKIP this WordPress has no Abilities API\n";
    exit(0);
}

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 1));

$apply = wp_get_ability('taxmod/apply');
$node  = wp_get_ability('taxmod/read-node');
$tree  = wp_get_ability('taxmod/read-tree');
$text  = (new WpdbNodeRepository())->byImplementations([TextType::class])[TextType::class] ?? null;
$baum  = $tree->execute(['depth' => 1]);

echo "Model\n";

$built = $apply->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'm', 'name' => '__nf Material', 'parent' => $baum['root']],
    ['op' => 'add_field', 'ref' => 'name', 'node' => '@m', 'target' => $text?->id ?? 0, 'name' => '__nf Name'],
    ['op' => 'add_field', 'ref' => 'farbe', 'node' => '@m', 'target' => $text?->id ?? 0, 'name' => '__nf Farbe'],
    ['op' => 'add_field', 'ref' => 'tags', 'node' => '@m', 'target' => $text?->id ?? 0, 'name' => '__nf Schlagworte', 'multiplicity' => '0..*'],
    ['op' => 'add_field', 'ref' => 'lang', 'node' => '@m', 'target' => $text?->id ?? 0, 'name' => '__nf Beschreibung'],
    ['op' => 'add_field', 'ref' => 'von', 'node' => '@m', 'target' => '@m', 'name' => '__nf Variante von', 'kind' => 'aggregation'],
    ['op' => 'set_addons', 'node' => $text?->id ?? 0, 'field' => '@farbe', 'addons' => [['name' => 'variant']]],
    ['op' => 'set_addons', 'node' => '@m', 'field' => '@von', 'addons' => [['name' => 'variant_of']]],
    ['op' => 'create_record', 'ref' => 'p', 'node' => '@m'],
    ['op' => 'set_values', 'record' => '@p', 'values' => ['@name' => 'OLED 0,91', '@farbe' => 'blau', '@lang' => str_repeat('lang ', 200), '@tags' => ['values' => ['i2c', 'spi', 'klein']]]],
    ['op' => 'create_record', 'ref' => 'v1', 'node' => '@m'],
    ['op' => 'set_values', 'record' => '@v1', 'values' => ['@von' => '@p', '@farbe' => 'weiß']],
    ['op' => 'create_record', 'ref' => 'v2', 'node' => '@m'],
    ['op' => 'set_values', 'record' => '@v2', 'values' => ['@von' => '@p']],
    ['op' => 'create_record', 'ref' => 'leer', 'node' => '@m'],
    ['op' => 'create_record', 'ref' => 'nurlang', 'node' => '@m'],
    ['op' => 'set_values', 'record' => '@nurlang', 'values' => ['@lang' => 'only the long text']],
]]);

check('apply builds the material', is_array($built), is_wp_error($built) ? $built->get_error_message() : '');

if (! is_array($built)) {
    echo "\n$ok ok, $bad failed\n";
    exit(1);
}

$ref   = $built['refs'];
$whole = (new ModelDoor())->records($ref['m']);
$only  = static fn (array $records, array $fields): array => array_map(static fn (array $v): array => array_intersect_key($v, array_flip($fields)), $records);
$same  = static function (array $fields) use ($ref, $whole, $only): bool {
    $narrow = (new ModelDoor())->recordsOfFields($ref['m'], $fields);
    $wanted = $only($whole, $fields);
    ksort($narrow);
    ksort($wanted);

    return array_map(static function (array $v): array { ksort($v); return $v; }, $narrow) === array_map(static function (array $v): array { ksort($v); return $v; }, $wanted);
};

echo "Reading\n";

$narrow = (new ModelDoor())->recordsOfFields($ref['m'], [$ref['name'], $ref['tags']]);
check('every record of the node is there, also one without a value in these fields', array_keys($narrow) == array_keys($whole) && ($narrow[$ref['leer']] ?? null) === [] && ($narrow[$ref['nurlang']] ?? null) === [], wp_json_encode(array_keys($narrow)));
check('a field not asked for is not there', ! isset($narrow[$ref['p']][$ref['lang']]) && ! isset($narrow[$ref['p']][$ref['farbe']]));
check('several values of a field in their order', ($narrow[$ref['p']][$ref['tags']] ?? []) === ['i2c', 'spi', 'klein'], wp_json_encode($narrow[$ref['p']] ?? null));
check('the same values as records() — name and several values', $same([$ref['name'], $ref['tags']]));
check('the same values as records() — a variant shows its parent\'s name though «Variante von» was not asked', $same([$ref['name']]) && ($narrow[$ref['v1']][$ref['name']] ?? []) === ['OLED 0,91'], wp_json_encode($narrow[$ref['v1']] ?? null));
check('the same values as records() — a «Je Variante» field, own and inherited', $same([$ref['farbe']]));
check('the same values as records() — the parent link itself', $same([$ref['von'], $ref['name']]));
check('the same values as records() — every field named', $same([$ref['name'], $ref['farbe'], $ref['tags'], $ref['lang'], $ref['von']]));

$hidden = $apply->execute(['changes' => [['op' => 'hide_field', 'node' => $ref['m'], 'field' => $ref['farbe'], 'hidden' => true]]]);

if (is_wp_error($hidden)) {
    check('a hidden field can be set up', false, $hidden->get_error_message());
} else {
    $whole = (new ModelDoor())->records($ref['m']);
    check('the same values as records() — with a hidden field', $same([$ref['name'], $ref['farbe'], $ref['tags']]));
}

echo "Cost\n";

$wpdb->query('DO 0');
$statements = [];
$listen     = static function (string $sql) use (&$statements): string { $statements[] = $sql; return $sql; };
add_filter('query', $listen);
(new ModelDoor())->recordsOfFields($ref['m'], [$ref['name']]);
remove_filter('query', $listen);
$values = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'relation_records') && str_contains($sql, 'value_text')));
check('the values come in one statement (CD-7)', count($values) === 1, count($values) . ' statements read values');
check('that statement names the fields and the node, not a list of record ids', isset($values[0]) && preg_match('/relation_id IN \(\d+(,\d+)*\)\s+AND node_record_id IN \(SELECT/', $values[0]) === 1, substr($values[0] ?? '', 0, 300));
check('the long text of a field not asked for is not read', isset($values[0]) && ! str_contains(wp_json_encode((new ModelDoor())->recordsOfFields($ref['m'], [$ref['name']])), 'lang lang'));

$door   = new ModelDoor();
$before = $wpdb->num_queries;
$door->recordsOfFields($ref['m'], [$ref['name']]);
$first  = $wpdb->num_queries - $before;
$before = $wpdb->num_queries;
$door->recordsOfFields($ref['m'], [$ref['name']]);
check('a door reads the same fields of a node once', $first > 0 && $wpdb->num_queries === $before, 'second read: ' . ($wpdb->num_queries - $before));

$door = new ModelDoor();
$all  = $door->records($ref['m']);
$before = $wpdb->num_queries;
$from = $door->recordsOfFields($ref['m'], [$ref['name'], $ref['tags']]);
ksort($from);
$wanted = $only($all, [$ref['name'], $ref['tags']]);
ksort($wanted);
check('a door that read the node whole answers the fields from that, without a statement', $wpdb->num_queries === $before && $from == $wanted, ($wpdb->num_queries - $before) . ' statements');

try {
    (new ModelDoor())->recordsOfFields($ref['m'], []);
    check('no field named is refused, not answered with everything', false);
} catch (\Taxmod\Core\Exception\ModelDoorRefused $e) {
    check('no field named is refused, not answered with everything', true);
}

$page = $node->execute(['node' => $ref['m'], 'fields' => [$ref['name']], 'limit' => 2]);
check('read-node with fields and a page still answers the page', count($page['records'] ?? []) === 2 && ($page['records'][0]['values'][0]['value'] ?? null) === 'OLED 0,91', wp_json_encode($page['records'] ?? null));

echo "\n$ok ok, $bad failed\n";
exit($bad === 0 ? 0 : 1);
