<?php declare(strict_types=1);
/**
 * A choice takes its roots from a structure setting, and a setting may name several (D-1546): the field keeps its target, the
 * choice offers only what lies under the roots — in the record editor filter and in the field controls alike.
 *
 *     php scripts/dev/choice-roots-check.php [path/to/wordpress]
 *
 * ```mermaid
 * flowchart LR
 *   S["setting: roots A, C"] --> E["record editor / field controls"]
 *   E --> O["offer: A, C and below; B never; the way down unpickable"]
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

$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $bad;
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . ($passed || $detail === '' ? '' : " — $detail") . "\n";
    $bad += $passed ? 0 : 1;
}

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 1));

$tree  = wp_get_ability('taxmod/read-tree')->execute(['depth' => 1]);
$model = (int) (array_values(array_filter($tree['rows'], static fn (array $r): bool => $r['parent'] === $tree['root'] && $r['name'] === 'Model'))[0]['id'] ?? 0);
$built = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'p', 'name' => '__crc Wurzelbaum', 'parent' => $model],
    ['op' => 'create_node', 'ref' => 'a', 'name' => '__crc Ast A', 'parent' => '@p'],
    ['op' => 'create_node', 'ref' => 'a1', 'name' => '__crc Blatt A1', 'parent' => '@a'],
    ['op' => 'create_node', 'ref' => 'b', 'name' => '__crc Ast B', 'parent' => '@p'],
    ['op' => 'create_node', 'ref' => 'b1', 'name' => '__crc Blatt B1', 'parent' => '@b'],
    ['op' => 'create_node', 'ref' => 'c', 'name' => '__crc Ast C', 'parent' => '@p'],
    ['op' => 'create_node', 'ref' => 'c1', 'name' => '__crc Blatt C1', 'parent' => '@c'],
    ['op' => 'create_node', 'ref' => 'r', 'name' => '__crc Regel', 'parent' => $model],
    ['op' => 'add_field', 'ref' => 'knoten', 'node' => '@r', 'target' => '@p', 'name' => '__crc Knoten', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
]]);

check('the model is built', is_array($built), is_wp_error($built) ? $built->get_error_message() : '');

if (! is_array($built)) {
    exit(1);
}

$ref = $built['refs'];
$set = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'set_setting', 'node' => $ref['p'], 'field' => $ref['knoten'], 'attribute' => 'renderer', 'value' => 'chooser'],
    ['op' => 'create_record', 'ref' => 'rule', 'node' => $ref['r']],
]]);
check('a rule is written', is_array($set), is_wp_error($set) ? $set->get_error_message() : '');

$rule   = is_array($set) ? (int) $set['refs']['rule'] : 0;
$roots  = [(int) $ref['knoten'] => [(int) $ref['a'], (int) $ref['c']]];
$offers = static fn (string $html, string $id): bool => (bool) preg_match('/\[' . $ref['knoten'] . '\][^>]*value="' . $id . '"|value="' . $id . '"[^>]*name="[^"]*\[' . $ref['knoten'] . '\]/', $html);

$whole  = (string) apply_filters(\Taxmod\WordPress\Plugin::RECORD_EDITOR_FILTER, '', $rule, '');
check('without roots the choice offers the whole target', str_contains($whole, '__crc Ast B') && str_contains($whole, '__crc Ast A'), substr($whole, 0, 2000));

$editor = (string) apply_filters(\Taxmod\WordPress\Plugin::RECORD_EDITOR_FILTER, '', $rule, '', [], $roots);
check('record editor: the roots and what lies below are offered', str_contains($editor, '__crc Ast A') && str_contains($editor, '__crc Blatt A1') && str_contains($editor, '__crc Ast C') && str_contains($editor, '__crc Blatt C1'), substr($editor, 0, 3000));
check('… a branch outside the roots is not', ! str_contains($editor, '__crc Ast B') && ! str_contains($editor, '__crc Blatt B1'), substr($editor, 0, 3000));
check('… the way down to a root is not chosen', ! $offers($editor, (string) $ref['p']) && $offers($editor, (string) $ref['a1']), substr($editor, 0, 3000));

$controls = (array) apply_filters(\Taxmod\WordPress\Plugin::FIELD_CONTROLS_FILTER, [], (int) $ref['r'], [(int) $ref['knoten']], '__crc', '__crc-form', $rule, [], $roots);
$control  = (string) ($controls[(int) $ref['knoten']] ?? '');
check('field controls: the same mechanism, several roots', str_contains($control, '__crc Ast A') && str_contains($control, '__crc Ast C') && ! str_contains($control, '__crc Ast B'), substr($control, 0, 3000));


echo $bad === 0 ? "\nall green\n" : "\n$bad failed\n";
exit($bad === 0 ? 0 : 1);
