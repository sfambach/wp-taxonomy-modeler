<?php declare(strict_types=1);
/**
 * A rule filter's field is chosen from the fields of the node chosen in the rule, inherited ones included (D-1541, his card
 * 21.11.2026 «Felder des gewählten Knotens»).
 *
 *     php scripts/dev/field-choice-check.php [path/to/wordpress]
 *
 * ```mermaid
 * flowchart LR
 *   R["rule: Knoten = Z"] --> F["filter part: Feld (int, field-choice, knoten_feld = Knoten)"]
 *   F --> O["offer: the fields of Z and of the nodes above it"]
 * ```
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

use Taxmod\Core\Model\Type\IntType;
use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;

$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $bad;
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . ($passed || $detail === '' ? '' : " — $detail") . "\n";
    $bad += $passed ? 0 : 1;
}

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 1));

$types = (new WpdbNodeRepository())->byImplementations([TextType::class, IntType::class]);
$int   = $types[IntType::class]?->id ?? 0;
$text  = $types[TextType::class]?->id ?? 0;
// Under «Model», where a rule's nodes live — a node outside every branch is not drawn as a part.
$tree  = wp_get_ability('taxmod/read-tree')->execute(['depth' => 1]);
$model = (int) (array_values(array_filter($tree['rows'], static fn (array $r): bool => $r['parent'] === $tree['root'] && $r['name'] === 'Model'))[0]['id'] ?? 0);
$built = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'p', 'name' => '__fcc Bauteile', 'parent' => $model],
    ['op' => 'add_field', 'ref' => 'bauform', 'node' => '@p', 'target' => $text, 'name' => '__fcc Bauform'],
    ['op' => 'create_node', 'ref' => 'z', 'name' => '__fcc Widerstände', 'parent' => '@p'],
    ['op' => 'add_field', 'ref' => 'wert', 'node' => '@z', 'target' => $text, 'name' => '__fcc Widerstandswert'],
    ['op' => 'create_node', 'ref' => 'f', 'name' => '__fcc Filter', 'parent' => $model],
    ['op' => 'add_field', 'ref' => 'feld', 'node' => '@f', 'target' => $int, 'name' => '__fcc Feld'],
    ['op' => 'add_field', 'ref' => 'fwert', 'node' => '@f', 'target' => $text, 'name' => '__fcc Wert'],
    ['op' => 'create_node', 'ref' => 'r', 'name' => '__fcc Regel', 'parent' => $model],
    ['op' => 'add_field', 'ref' => 'knoten', 'node' => '@r', 'target' => '@p', 'name' => '__fcc Knoten', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
    ['op' => 'add_field', 'ref' => 'filter', 'node' => '@r', 'target' => '@f', 'name' => '__fcc Filter', 'kind' => 'composition', 'multiplicity' => '0..*'],
]]);

check('the rule model is built', is_array($built), is_wp_error($built) ? $built->get_error_message() : '');

if (! is_array($built)) {
    exit(1);
}

$ref = $built['refs'];
$set = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'set_setting', 'node' => $int, 'field' => $ref['feld'], 'attribute' => 'renderer', 'value' => 'field-choice'],
    ['op' => 'set_setting', 'node' => $int, 'field' => $ref['feld'], 'attribute' => 'knoten_feld', 'value' => (string) $ref['knoten']],
    // His word 21.11.2026 «knoten auswahl funktioniert nicht … default renderer»: the chooser at the use site of «Knoten».
    ['op' => 'set_setting', 'node' => $ref['p'], 'field' => $ref['knoten'], 'attribute' => 'renderer', 'value' => 'chooser'],
    ['op' => 'create_record', 'ref' => 'rule', 'node' => $ref['r']],
    ['op' => 'set_values', 'record' => '@rule', 'values' => [(string) $ref['knoten'] => (string) $ref['z'], (string) $ref['filter'] => ['n1' => [(string) $ref['feld'] => (string) $ref['wert'], (string) $ref['fwert'] => 'SMD']]]],
]]);
check('the field choice is set at the filter field and a rule written', is_array($set), is_wp_error($set) ? $set->get_error_message() : '');

// The record editor of a module's page (D-1025) — where a rule is edited.
$drawn = is_array($set) ? (string) apply_filters(\Taxmod\WordPress\Plugin::RECORD_EDITOR_FILTER, '', (int) $set['refs']['rule'], '') : '';
$drawn = (string) preg_replace("/^.*?(<select[^>]*\\[" . $ref["feld"] . "\\][^>]*>.*?<\\/select>).*\$/s", "\$1", $drawn);
check('the filter offers the fields of the chosen node by name', str_contains($drawn, '__fcc Widerstandswert'), $drawn);
check('… and those it inherits from the nodes above', str_contains($drawn, '__fcc Bauform'), $drawn);
check('… the stored field is chosen, stored as its relation id', (bool) preg_match('/value="' . $ref['wert'] . '"[^>]*selected/', $drawn), $drawn);
// A rule's node is a node of the tree, not a record — as a data job sets it at «Knoten» and «Wofür».
$editor = is_array($set) ? (string) apply_filters(\Taxmod\WordPress\Plugin::RECORD_EDITOR_FILTER, '', (int) $set['refs']['rule'], '') : '';
check('with the chooser at «Knoten» the rule chooses a node of the tree, not a record', (bool) preg_match('/value="' . $ref['z'] . '" selected>__fcc Widerstände/', $editor) && ! str_contains($editor, 'taxmod-record-search'), substr($editor, 0, 3000));
check('… and no field of another node is offered', ! str_contains($drawn, '__fcc Feld<') && ! str_contains($drawn, '__fcc Knoten<'), $drawn);

echo $bad === 0 ? "\nall green\n" : "\n$bad failed\n";
exit($bad === 0 ? 0 : 1);
