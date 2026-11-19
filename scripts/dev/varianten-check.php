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
    // A value of its own from before it became a variant (as the displays of step 8 will have).
    ['op' => 'set_values', 'record' => '@v3', 'values' => ['@name' => 'stray own name']],
    ['op' => 'set_values', 'record' => '@v3', 'values' => ['@von' => '@p', '@farbe' => 'gelb']],
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

echo "Writing (step 4)\n";

$set = static fn (int $record, array $values): mixed => $apply->execute(['changes' => [['op' => 'set_values', 'record' => $record, 'values' => $values]]]);
$why = static fn (mixed $answer): string => is_wp_error($answer) ? $answer->get_error_message() : 'accepted';

$answer = $set($ref['v1'], [(string) $ref['name'] => 'own name']);
check('a variant refuses a value on its parent\'s field', is_wp_error($answer) && str_contains($why($answer), 'parent'), $why($answer));
$answer = $set($ref['v1'], [(string) $ref['farbe'] => 'grün']);
check('a variant takes a value on a «Je Variante» field', ! is_wp_error($answer), $why($answer));
$answer = $set($ref['v3'], [(string) $ref['name'] => 'stray own name']);
check('an unchanged value a variant already holds does not block a save', ! is_wp_error($answer), $why($answer));
$answer = $set($ref['v1'], [(string) $ref['von'] => (string) $ref['v1']]);
check('a record is no variant of itself', is_wp_error($answer), $why($answer));
$answer = $set($ref['p'], [(string) $ref['von'] => (string) $ref['v1']]);
check('a record with variants is no variant', is_wp_error($answer), $why($answer));
$answer = $apply->execute(['changes' => [
    ['op' => 'create_record', 'ref' => 'v4', 'node' => $ref['m']],
    ['op' => 'set_values', 'record' => '@v4', 'values' => [(string) $ref['von'] => (string) $ref['v1']]],
]]);
check('no variant of a variant', is_wp_error($answer), $why($answer));
$answer = $set($ref['p'], [(string) $ref['name'] => 'OLED 0,96']);
$shown  = (new ModelDoor())->records($ref['m']);
check('a change at the parent shows at every variant', ! is_wp_error($answer) && ($shown[$ref['v2']][$ref['name']] ?? []) === ['OLED 0,96'], $why($answer));

echo "Making variants (D-1478)\n";

$made = $apply->execute(['changes' => [
    ['op' => 'create_record', 'ref' => 'a', 'node' => $ref['m'], 'values' => [(string) $ref['name'] => 'TFT 1,8', (string) $ref['farbe'] => 'rot']],
    ['op' => 'create_record', 'ref' => 'b', 'node' => $ref['m'], 'values' => [(string) $ref['name'] => 'TFT 1,8', (string) $ref['farbe'] => 'grün']],
    ['op' => 'create_record', 'ref' => 'c', 'node' => $ref['m'], 'values' => [(string) $ref['name'] => 'TFT 2,4']],
]]);
$r      = is_array($made) ? $made['refs'] : [];
$answer = $apply->execute(['changes' => [['op' => 'make_variants', 'records' => [$r['a'] ?? 0, $r['c'] ?? 0]]]]);
check('make_variants refuses a difference outside «Je Variante», naming the field', is_wp_error($answer) && str_contains($why($answer), '__va Name'), $why($answer));
$answer = $apply->execute(['changes' => [['op' => 'make_variants', 'ref' => 'vater', 'records' => [$r['a'] ?? 0, $r['b'] ?? 0]]]]);
$vater  = is_array($answer) ? (int) ($answer['refs']['vater'] ?? 0) : 0;
$shown  = (new ModelDoor())->records($ref['m']);
check('make_variants: a new parent holds the shared name', $vater > 0 && ($shown[$vater][$ref['name']] ?? []) === ['TFT 1,8'], $why($answer));
check('make_variants: each keeps its id and its own colour', ($shown[$r['a']][$ref['farbe']] ?? []) === ['rot'] && ($shown[$r['b']][$ref['von']] ?? []) === [(string) $vater]);
$added  = $apply->execute(['changes' => [['op' => 'add_variant', 'ref' => 'neu', 'record' => $r['a'] ?? 0, 'values' => [(string) $ref['farbe'] => 'blau']]]]);
$neu    = is_array($added) ? (int) ($added['refs']['neu'] ?? 0) : 0;
$shown  = (new ModelDoor())->records($ref['m']);
check('add_variant at a variant adds a sibling under the same parent', $neu > 0 && ($shown[$neu][$ref['von']] ?? []) === [(string) $vater] && ($shown[$neu][$ref['farbe']] ?? []) === ['blau'], $why($added));
$links  = (new ModelDoor())->variantParents([$ref['m']]);
check('variantParents names each variant with its parent (D-1479)', ($links[$r['a'] ?? 0] ?? 0) === $vater && ($links[$ref['v1']] ?? 0) === $ref['p'] && ! isset($links[$vater]), wp_json_encode($links));
$undo   = $apply->execute(['changes' => [['op' => 'undo_act', 'act' => (int) ($answer['act'] ?? 0)]]]);
$shown  = (new ModelDoor())->records($ref['m']);
check('undo takes make_variants back as one act', ! is_wp_error($undo) && ($shown[$r['a']][$ref['name']] ?? []) === ['TFT 1,8'] && ! isset($shown[$vater]), $why($undo));

echo "Merging a doubled record (D-1480)\n";

$made   = $apply->execute(['changes' => [
    ['op' => 'create_record', 'ref' => 'x', 'node' => $ref['m'], 'values' => [(string) $ref['name'] => 'LCD 1602']],
    ['op' => 'create_record', 'ref' => 'y', 'node' => $ref['m'], 'values' => [(string) $ref['name'] => 'LCD 1602']],
    ['op' => 'create_record', 'ref' => 'z', 'node' => $ref['m'], 'values' => [(string) $ref['name'] => 'LCD 1602', (string) $ref['farbe'] => 'gelb']],
]]);
$r      = is_array($made) ? $made['refs'] : [];
$answer = $apply->execute(['changes' => [['op' => 'merge_record', 'from' => $r['z'] ?? 0, 'into' => $r['x'] ?? 0]]]);
check('merge_record refuses a value into lacks, naming the field', is_wp_error($answer) && str_contains($why($answer), '__va Farbe'), $why($answer));
$veto   = static fn (array $out, int $from): array => $from === ($r['y'] ?? -1) ? ['veto' => 'Mindestbestand differs'] : $out;
add_filter('taxmod_merge_record', $veto, 10, 2);
$answer = $apply->execute(['changes' => [['op' => 'merge_record', 'from' => $r['y'] ?? 0, 'into' => $r['x'] ?? 0]]]);
remove_filter('taxmod_merge_record', $veto, 10);
check('a module veto refuses the merge', is_wp_error($answer) && str_contains($why($answer), 'Mindestbestand differs'), $why($answer));
$answer = $apply->execute(['changes' => [['op' => 'merge_record', 'from' => $r['y'] ?? 0, 'into' => $r['x'] ?? 0]]]);
$shown  = (new ModelDoor())->records($ref['m']);
check('merge_record trashes from and names it', ! is_wp_error($answer) && ! isset($shown[$r['y'] ?? 0]) && in_array($r['y'] ?? 0, $answer['applied'][0]['merged']['trashed'] ?? [], true), $why($answer));
$undo   = $apply->execute(['changes' => [['op' => 'undo_act', 'act' => (int) ($answer['act'] ?? 0)]]]);
$shown  = (new ModelDoor())->records($ref['m']);
check('undo takes merge_record back as one act', ! is_wp_error($undo) && isset($shown[$r['y'] ?? 0]), $why($undo));

echo "\n$ok ok, $bad failed\n";
exit($bad === 0 ? 0 : 1);
