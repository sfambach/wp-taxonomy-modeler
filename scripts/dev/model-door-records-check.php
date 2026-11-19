<?php declare(strict_types=1);
/**
 * A module reads the records of a node through the door and gets what `read-node` answers for it — the values in one query,
 * folded without holding the answer (D-1489).
 *
 *     php scripts/dev/model-door-records-check.php [path/to/wordpress]
 *
 * ⚠️ *Measured 2026-11-19 on a seed shaped like the live data (3038 purchases, 69,542 value rows): the reading chain cost some
 * 900 bytes a value held and 1500–1700 at its peak; a tab of the stock's goods receipt peaked at 137–149 MB over the base and
 * at 46–58 MB folded. The narrow read of D-1486 this check guarded before had no effect live and is gone (D-1491).*
 *
 * ```mermaid
 * flowchart LR
 *   M["module: node"] --> D["ModelDoor::records"]
 *   D --> R["one query: the value rows of the node, streamed"]
 *   R --> F["record → field → raw values"]
 *   F --> S["the same values as read-node answers"]
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
// D-1489: the door folds the value rows itself; the yardstick is the other way to the same values — what `read-node` answers.
$viaAbility = static function (int $id) use ($node): array {
    $out = [];

    foreach ($node->execute(['node' => $id, 'used_by' => false])['records'] ?? [] as $record) {
        $out[(int) $record['id']] = [];

        foreach ($record['values'] ?? [] as $value) {
            $out[(int) $record['id']][(int) $value['relation_id']][] = (string) $value['value'];
        }
    }

    return $out;
};
$whole = (new ModelDoor())->records($ref['m']);

echo "Reading\n";

// Variants aside (the door shows a variant its parent's values, `read-node` the stored ones): the records that are no variant.
$stored = $viaAbility($ref['m']);
check('records() gives what read-node answers — every record, every stored value (D-1489)', array_keys($whole) == array_keys($stored)
    && $whole[$ref['p']] == $stored[$ref['p']] && $whole[$ref['leer']] === [] && $whole[$ref['nurlang']] == $stored[$ref['nurlang']], wp_json_encode([$whole[$ref['p']] ?? null, $stored[$ref['p']] ?? null]));

$kinds = $apply->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'k', 'name' => '__nf Werte', 'parent' => $baum['root']],
    ['op' => 'add_field', 'ref' => 'zahl', 'node' => '@k', 'target' => 1173, 'name' => '__nf Menge'],
    ['op' => 'add_field', 'ref' => 'ganz', 'node' => '@k', 'target' => 1171, 'name' => '__nf Anzahl'],
    ['op' => 'add_field', 'ref' => 'tag', 'node' => '@k', 'target' => 1183, 'name' => '__nf Tag'],
    ['op' => 'add_field', 'ref' => 'ja', 'node' => '@k', 'target' => 1179, 'name' => '__nf Ja'],
    ['op' => 'add_field', 'ref' => 'zu', 'node' => '@k', 'target' => '@k', 'name' => '__nf Verweis', 'kind' => 'aggregation'],
    ['op' => 'create_record', 'ref' => 'a', 'node' => '@k'],
    ['op' => 'set_values', 'record' => '@a', 'values' => ['@zahl' => '1', '@ganz' => '0', '@tag' => '2026-11-19', '@ja' => '1']],
    ['op' => 'create_record', 'ref' => 'b', 'node' => '@k'],
    ['op' => 'set_values', 'record' => '@b', 'values' => ['@zahl' => '2.50', '@ganz' => '-7', '@tag' => '2026-11', '@ja' => '0', '@zu' => '@a']],
    ['op' => 'create_record', 'ref' => 'c', 'node' => '@k'],
    ['op' => 'set_values', 'record' => '@c', 'values' => ['@zahl' => '0', '@tag' => '2026', '@zu' => '@b']],
]]);

if (is_wp_error($kinds)) {
    check('a node with a number, a count, a date, a yes/no and a reference can be set up', false, $kinds->get_error_message());
} else {
    $k      = $kinds['refs'];
    $folded = (new ModelDoor())->records($k['k']);
    $asked  = $viaAbility($k['k']);
    ksort($folded);
    ksort($asked);
    $sorted = static fn (array $records): array => array_map(static function (array $v): array { ksort($v); return $v; }, $records);
    check('number, count, date (day, month, year), yes/no and reference: records() gives exactly what read-node answers', $sorted($folded) === $sorted($asked), wp_json_encode([$sorted($folded), $sorted($asked)]));
    check('a number is not padded with the zeros of its column', ($folded[$k['a']][$k['zahl']] ?? null) === ['1'] && ! str_contains(wp_json_encode($folded), '.0000'), wp_json_encode($folded[$k['a']] ?? null));
}

check('every record of the node is there, also one without a value', array_keys($whole) == array_keys($stored) && ($whole[$ref['leer']] ?? null) === []);
check('several values of a field in their order', ($whole[$ref['p']][$ref['tags']] ?? []) === ['i2c', 'spi', 'klein'], wp_json_encode($whole[$ref['p']] ?? null));
check('a variant shows its parent\'s name (D-1208)', ($whole[$ref['v1']][$ref['name']] ?? []) === ['OLED 0,91'] && ($whole[$ref['v2']][$ref['name']] ?? []) === ['OLED 0,91'], wp_json_encode([$whole[$ref['v1']] ?? null, $whole[$ref['v2']] ?? null]));
check('a «Je Variante» field: the variant\'s own value, and the parent\'s where it has none', ($whole[$ref['v1']][$ref['farbe']] ?? []) === ['weiß'] && ($whole[$ref['v2']][$ref['farbe']] ?? []) === ['blau'], wp_json_encode([$whole[$ref['v1']] ?? null, $whole[$ref['v2']] ?? null]));
check('the parent link of a variant is there', ($whole[$ref['v1']][$ref['von']] ?? []) === [(string) $ref['p']]);

$hidden = $apply->execute(['changes' => [['op' => 'hide_field', 'node' => $ref['m'], 'field' => $ref['farbe'], 'hidden' => true]]]);

if (is_wp_error($hidden)) {
    check('a hidden field can be set up', false, $hidden->get_error_message());
} else {
    $now = (new ModelDoor())->records($ref['m']);
    check('a hidden field keeps its values for a module, as read-node keeps them', ($now[$ref['p']] ?? null) == ($viaAbility($ref['m'])[$ref['p']] ?? []), wp_json_encode($now[$ref['p']] ?? null));
}

echo "Cost\n";

$wpdb->query('DO 0');
$statements = [];
$listen     = static function (string $sql) use (&$statements): string { $statements[] = $sql; return $sql; };
add_filter('query', $listen);
(new ModelDoor())->records($ref['m']);
remove_filter('query', $listen);
$values = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'relation_records') && str_contains($sql, 'value_text')));
check('the values of the node come in one statement, and a counter of statements sees it (CD-7)', count($values) === 1, count($values) . ' statements read values');
check('that statement names the node, not a list of record ids', isset($values[0]) && preg_match('/WHERE node_record_id IN \(SELECT id FROM \S+ WHERE node_id = \d+ AND relation_id = 0\)/', $values[0]) === 1, substr((string) ($values[0] ?? ''), 0, 300));

$door   = new ModelDoor();
$before = $wpdb->num_queries;
$door->records($ref['m']);
$first  = $wpdb->num_queries - $before;
$before = $wpdb->num_queries;
$door->records($ref['m']);
check('a door reads a node once', $first > 0 && $wpdb->num_queries === $before, 'second read: ' . ($wpdb->num_queries - $before));

$wrote  = $door->write([['op' => 'set_values', 'record' => $ref['leer'], 'values' => [(string) $ref['name'] => 'jetzt mit Namen']]]);
check('after a write through the door the node is read afresh', ($door->records($ref['m'])[$ref['leer']][$ref['name']] ?? []) === ['jetzt mit Namen'], wp_json_encode($wrote['act'] ?? null));

$page = $node->execute(['node' => $ref['m'], 'fields' => [$ref['name']], 'limit' => 2]);
check('read-node with fields and a page still answers the page', count($page['records'] ?? []) === 2 && ($page['records'][0]['values'][0]['value'] ?? null) === 'OLED 0,91', wp_json_encode($page['records'] ?? null));

echo "\n$ok ok, $bad failed\n";
exit($bad === 0 ? 0 : 1);
