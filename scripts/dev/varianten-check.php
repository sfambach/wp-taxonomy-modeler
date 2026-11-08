<?php declare(strict_types=1);
/**
 * A variant reads its parent's values at one place, its own only on «Je Variante» fields (D-1208, step 3).
 *
 *     php scripts/dev/varianten-check.php [path/to/wordpress]
 *
 * ```mermaid
 * flowchart LR
 *   A["apply: node, «Variante von», «Je Variante»"] --> F["read-node names the add-ons per field"]
 *   F --> D["ModelDoor: records / valuesOf"]
 *   D --> S["variant shows parent's values, own colour"]
 *   D --> Q["three variants cost no more reads than one"]
 * ```
 *
 * @see docs/pakete/varianten/plan.md
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
    ['op' => 'create_node', 'ref' => 'm', 'name' => '__va Material', 'parent' => $baum['root']],
    ['op' => 'add_field', 'ref' => 'name', 'node' => '@m', 'target' => $text?->id ?? 0, 'name' => '__va Name'],
    ['op' => 'add_field', 'ref' => 'farbe', 'node' => '@m', 'target' => $text?->id ?? 0, 'name' => '__va Farbe'],
    ['op' => 'add_field', 'ref' => 'von', 'node' => '@m', 'target' => '@m', 'name' => '__va Variante von', 'kind' => 'aggregation'],
    ['op' => 'set_addons', 'node' => $text?->id ?? 0, 'field' => '@farbe', 'addons' => [['name' => 'variant']]],
    ['op' => 'set_addons', 'node' => '@m', 'field' => '@von', 'addons' => [['name' => 'variant_of']]],
    ['op' => 'create_record', 'ref' => 'p', 'node' => '@m'],
    ['op' => 'set_values', 'record' => '@p', 'values' => ['@name' => 'OLED 0,91', '@farbe' => 'blau']],
    ['op' => 'create_record', 'ref' => 'v1', 'node' => '@m'],
    ['op' => 'set_values', 'record' => '@v1', 'values' => ['@von' => '@p', '@farbe' => 'weiß']],
    ['op' => 'create_record', 'ref' => 'v2', 'node' => '@m'],
    ['op' => 'set_values', 'record' => '@v2', 'values' => ['@von' => '@p']],
    ['op' => 'create_record', 'ref' => 'v3', 'node' => '@m'],
    ['op' => 'set_values', 'record' => '@v3', 'values' => ['@von' => '@p', '@farbe' => 'gelb', '@name' => 'stray own name']],
]]);

check('apply builds the material with three variants', is_array($built), is_wp_error($built) ? $built->get_error_message() : '');

if (! is_array($built)) {
    echo "\n$ok ok, $bad failed\n";
    exit(1);
}

$ref = $built['refs'];

$fields = array_column($node->execute(['node' => $ref['m'], 'records' => false])['fields'], null, 'relation_id');
check('read-node names «Je Variante» at the colour', ($fields[$ref['farbe']]['addons'] ?? []) === ['variant'], wp_json_encode($fields[$ref['farbe']] ?? null));
check('read-node names «Variante von» at the parent link', ($fields[$ref['von']]['addons'] ?? []) === ['variant_of'], wp_json_encode($fields[$ref['von']] ?? null));
check('a field without add-on carries none', ! isset($fields[$ref['name']]['addons']));

echo "Reading\n";

$records = (new ModelDoor())->records($ref['m']);
check('the parent keeps its own values', ($records[$ref['p']][$ref['name']] ?? []) === ['OLED 0,91'] && ($records[$ref['p']][$ref['farbe']] ?? []) === ['blau']);
check('a variant shows the parent\'s name', ($records[$ref['v1']][$ref['name']] ?? []) === ['OLED 0,91'], wp_json_encode($records[$ref['v1']] ?? null));
check('a variant shows its own colour', ($records[$ref['v1']][$ref['farbe']] ?? []) === ['weiß']);
check('a variant without a colour shows the parent\'s', ($records[$ref['v2']][$ref['farbe']] ?? []) === ['blau']);
check('a value of its own on a parent\'s field does not show', ($records[$ref['v3']][$ref['name']] ?? []) === ['OLED 0,91']);
check('a variant keeps its parent link', ($records[$ref['v1']][$ref['von']] ?? []) === [(string) $ref['p']]);

$before   = $wpdb->num_queries;
$one      = (new ModelDoor())->valuesOf([$ref['v1']]);
$forOne   = $wpdb->num_queries - $before;
$before   = $wpdb->num_queries;
$three    = (new ModelDoor())->valuesOf([$ref['v1'], $ref['v2'], $ref['v3']]);
$forThree = $wpdb->num_queries - $before;

check('valuesOf shows the same as records', ($one[$ref['v1']] ?? null) == ($records[$ref['v1']] ?? null), wp_json_encode($one));
check('valuesOf reads the parent outside the list', ($three[$ref['v2']][$ref['name']] ?? []) === ['OLED 0,91'] && ($three[$ref['v2']][$ref['farbe']] ?? []) === ['blau'], wp_json_encode($three));
check('three variants cost no more reads than one (CD-7)', $forThree <= $forOne, "one: $forOne, three: $forThree");

echo "\n$ok ok, $bad failed\n";
exit($bad === 0 ? 0 : 1);
