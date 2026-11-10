<?php declare(strict_types=1);
/**
 * A module's form uses the modeller's own field controls and reads them back the same way (D-1250).
 *
 *     php scripts/dev/field-controls-check.php [path/to/wordpress]
 *
 * @see docs/gestaltung-und-architektur.md A8
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
use Taxmod\WordPress\ModelDoor;
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
$built = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'k', 'name' => '__fc Kauf', 'parent' => wp_get_ability('taxmod/read-tree')->execute(['depth' => 1])['root']],
    ['op' => 'add_field', 'ref' => 'name', 'node' => '@k', 'target' => $types[TextType::class]?->id ?? 0, 'name' => '__fc Name'],
    ['op' => 'add_field', 'ref' => 'count', 'node' => '@k', 'target' => $types[IntType::class]?->id ?? 0, 'name' => '__fc Anzahl'],
    ['op' => 'create_record', 'ref' => 'r', 'node' => '@k'],
    ['op' => 'set_values', 'record' => '@r', 'values' => ['@name' => 'Edge Card', '@count' => '5']],
]]);

check('the test node is built', is_array($built), is_wp_error($built) ? $built->get_error_message() : '');

if (! is_array($built)) {
    exit(1);
}

$ref  = $built['refs'];
$door = new ModelDoor();

$new = $door->controls($ref['k'], [$ref['name'], $ref['count']], 'buy', 'f1', 0, [$ref['count'] => '10']);
check('the controls are the modeller\'s, named prefix[relation]', str_contains($new[$ref['count']] ?? '', 'name="buy[' . $ref['count'] . ']"') && str_contains($new[$ref['count']] ?? '', 'form="f1"'), wp_json_encode($new));
check('a whole number gets the number control of its type', str_contains($new[$ref['count']] ?? '', 'inputmode="numeric"'));
check('a value handed in stands in the control', str_contains($new[$ref['count']] ?? '', 'value="10"'));
check('a field not handed in stays empty, no made-up default', ! str_contains($new[$ref['name']] ?? '', 'value='), $new[$ref['name']] ?? '');

$held = $door->controls($ref['k'], [$ref['count']], 'buy', '', $ref['r']);
check('a record\'s own value stands in its control', str_contains($held[$ref['count']] ?? '', 'value="5"'), $held[$ref['count']] ?? '');
check('only the fields asked for are drawn', array_keys($held) === [$ref['count']]);

$typed = $door->typed($ref['k'], [$ref['name'] => 'Edge \\"x\\"', $ref['count'] => '10', 999 => 'not a field']);
check('typed values come back raw, unslashed', ($typed[$ref['name']] ?? null) === 'Edge "x"' && ($typed[$ref['count']] ?? null) === '10', wp_json_encode($typed));
check('an empty field is left out', ! isset($door->typed($ref['k'], [$ref['count'] => ''])[$ref['count']]));
check('a field the node does not have is left out', ! isset($typed[999]));

$arten = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'a', 'name' => '__fc Arten', 'parent' => wp_get_ability('taxmod/read-tree')->execute(['depth' => 1])['root'], 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'ein', 'name' => '__fc Eingang', 'parent' => '@a', 'class' => 'Constant'],
    ['op' => 'create_node', 'ref' => 'v', 'name' => '__fc Verbrauch', 'parent' => '@a', 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'bb', 'name' => '__fc Betriebsbedarf', 'parent' => '@v', 'class' => 'Constant'],
    ['op' => 'add_field', 'ref' => 'art', 'node' => $ref['k'], 'target' => '@a', 'name' => '__fc Art', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
]]);
$ar     = is_array($arten) ? $arten['refs'] : [];
$ganz   = $ar === [] ? '' : ($door->controls($ref['k'], [$ar['art']], 'buy')[$ar['art']] ?? '');
$engen  = $ar === [] ? '' : ($door->controls($ref['k'], [$ar['art']], 'buy', '', 0, [], [$ar['art'] => $ar['v']])[$ar['art']] ?? '');
$fremd  = $ar === [] ? '' : ($door->controls($ref['k'], [$ar['art']], 'buy', '', 0, [], [$ar['art'] => $ref['k']])[$ar['art']] ?? '');
check('without a root the chooser offers the whole target', str_contains($ganz, '__fc Eingang') && str_contains($ganz, '__fc Betriebsbedarf'), $ganz);
check('a root narrows the chooser to its branch (D-1298)', str_contains($engen, '__fc Betriebsbedarf') && ! str_contains($engen, '__fc Eingang'), $engen);
check('only leaves are offered, not the branch itself', $ar !== [] && ! str_contains($engen, 'value="' . $ar['v'] . '"'), $engen);
check('a root outside the target is ignored', str_contains($fremd, '__fc Eingang'), $fremd);

wp_set_current_user(0);
check('without the capability no control is drawn', $door->controls($ref['k'], [$ref['count']], 'buy') === []);

echo $bad === 0 ? "\nall green\n" : "\n$bad failed\n";
exit($bad === 0 ? 0 : 1);
