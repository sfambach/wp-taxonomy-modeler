<?php declare(strict_types=1);
/**
 * A module reads a unit value in its base unit and writes one back, before and after a field becomes a unit value (Feldkatalog 9/10/12).
 *
 *     php scripts/dev/amounts-check.php [path/to/wordpress]
 *
 * ⚠️ *Measured at the result: 62 mm come as 0.062, an old number not at all, a written 0.05 stands as 0.05 Meter and goes with its
 * undo, a plain number and a field with two units without a conversion are refused.*
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
use Taxmod\Core\Model\Type\DecimalType;
use Taxmod\WordPress\ModelDoor;
use Taxmod\WordPress\Persistence\UnitScaffold;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
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

$plugin  = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($plugin, 'file'))->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$model   = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin)->rootOf(Branch::Model);
$decimal = (new WpdbNodeRepository())->byImplementations([DecimalType::class])[DecimalType::class] ?? null;
$door     = new ModelDoor();
[$wert, $einheit, $praefix] = (new ReflectionMethod($door, 'unitValueFields'))->invoke($door);
$units    = (int) get_option(UnitScaffold::NODE_OPTION_PREFIX . 'base_units', 0);
$prefixes = (int) get_option(UnitScaffold::NODE_OPTION_PREFIX . 'prefixes', 0);
// The prefix with the factor 0.001 — found by its conversion, not by its name.
$milli = 0;

foreach (array_keys($door->children($prefixes)) as $id) {
    $resolver = (new ReflectionMethod($door, 'conversionsOfNodes'))->invoke($door, [$id]);

    if (isset($resolver[$id]) && \Taxmod\Core\Service\KeyValues::normal($resolver[$id]['factor']) === '0.001') {
        $milli = $id;
    }
}

check('the unit-value gerüst is there', $wert > 0 && $einheit > 0 && $praefix > 0 && $units > 0 && $milli > 0, "$wert / $einheit / $praefix / $units / $milli");

$gebaut = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'm', 'name' => '__am Meter', 'parent' => $units, 'class' => 'Unit'],
    ['op' => 'create_node', 'ref' => 'm2', 'name' => '__am Elle', 'parent' => $units, 'class' => 'Unit'],
    ['op' => 'create_node', 'ref' => 'z', 'name' => '__am Zoll', 'parent' => $units, 'class' => 'Unit'],
    ['op' => 'set_setting', 'node' => '@z', 'attribute' => 'umrechnung', 'value' => 'conversion'],
    ['op' => 'set_setting', 'node' => '@z', 'attribute' => 'factor', 'value' => '0.0254'],
    ['op' => 'create_node', 'ref' => 'b', 'name' => '__am Breite', 'parent' => UnitScaffold::unitValueId(), 'class' => 'UnitValue'],
    ['op' => 'set_list', 'node' => '@b', 'attribute' => 'erlaubte_einheiten', 'members' => ['@m', '@z']],
    ['op' => 'create_node', 'ref' => 'h', 'name' => '__am Höhe', 'parent' => UnitScaffold::unitValueId(), 'class' => 'UnitValue'],
    ['op' => 'set_list', 'node' => '@h', 'attribute' => 'erlaubte_einheiten', 'members' => ['@m', '@m2']],
    ['op' => 'create_node', 'ref' => 'e', 'name' => '__am Etikett', 'parent' => $model->id],
    ['op' => 'add_field', 'ref' => 'f', 'node' => '@e', 'target' => '@b', 'name' => '__am Breite', 'kind' => 'composition'],
    ['op' => 'add_field', 'ref' => 'g', 'node' => '@e', 'target' => '@h', 'name' => '__am Höhe', 'kind' => 'composition'],
    ['op' => 'add_field', 'ref' => 'n', 'node' => '@e', 'target' => $decimal?->id ?? 0, 'name' => '__am Rolle'],
    ['op' => 'create_record', 'ref' => 'r', 'node' => '@e'],
    ['op' => 'create_record', 'ref' => 's', 'node' => '@e'],
]]);

check('the test model is built', is_array($gebaut), is_wp_error($gebaut) ? $gebaut->get_error_message() : '');

if (! is_array($gebaut)) {
    exit(1);
}

$r = $gebaut['refs'];
[$f, $g, $n, $record] = [(int) $r['f'], (int) $r['g'], (int) $r['n'], (int) $r['r']];

$door->write([['op' => 'set_values', 'record' => (string) $record, 'values' => [
    (string) $f => ['neu' => [(string) $wert => '62', (string) $praefix => (string) $milli, (string) $einheit => (string) $r['m']]],
    (string) $n => '62',
]]]);

echo "\n== 1. Read ==\n";
check('only the unit-value fields are amount fields', $door->amountFields([$n, $f, $g]) === [$f, $g], json_encode($door->amountFields([$n, $f, $g])));
$amounts = $door->amountsOf([$record], [$f, $g, $n]);
check('62 mm come as 0.062', abs(($amounts[$record][$f] ?? -1) - 0.062) < 1e-12, json_encode($amounts));
check('an old number and an empty field are absent', ! isset($amounts[$record][$n]) && ! isset($amounts[$record][$g]), json_encode($amounts));

echo "\n== 2. Write ==\n";
$act     = $door->putAmount($record, $f, 0.05);
$amounts = $door->amountsOf([$record], [$f]);
check('a written amount names its act', $act > 0);
check('it stands in the base unit', abs(($amounts[$record][$f] ?? -1) - 0.05) < 1e-12, json_encode($amounts));
$part = (int) ($door->valuesOf([$record])[$record][$f][0] ?? 0);
$teil = $door->valuesOf([$part])[$part] ?? [];
check('with the unit without a conversion, no prefix', (string) ($teil[$einheit][0] ?? '') === (string) $r['m'] && ($teil[$praefix] ?? []) === [], json_encode($teil));

$door->undo($act);
$amounts = $door->amountsOf([$record], [$f]);
check('its undo brings back 62 mm', abs(($amounts[$record][$f] ?? -1) - 0.062) < 1e-12, json_encode($amounts));

$leer   = (int) $r['s'];
$door->putAmount($leer, $f, 0.03);
$amounts = $door->amountsOf([$leer], [$f]);
check('a field without a part gets one', abs(($amounts[$leer][$f] ?? -1) - 0.03) < 1e-12, json_encode($amounts));

echo "\n== 3. One save, one undo ==\n";
$bracket = ModelDoor::inAct(static function () use ($door, $record, $leer, $f, $n): array {
    return [$door->putAmount($record, $f, 0.07), $door->putAmount($leer, $f, 0.08), (int) ($door->write([['op' => 'set_values', 'record' => (string) $record, 'values' => [(string) $n => '70']]])['act'] ?? 0)];
});
check('amounts written in a bracket join the caller\'s act', array_unique($bracket['result']) === [(int) $bracket['act']], json_encode($bracket));
$door->undo((int) $bracket['act']);
$amounts = $door->amountsOf([$record, $leer], [$f]);
check('one undo takes all of them back', abs(($amounts[$record][$f] ?? -1) - 0.062) < 1e-12 && abs(($amounts[$leer][$f] ?? -1) - 0.03) < 1e-12, json_encode($amounts));

$refused = static function (callable $write): bool {
    try {
        $write();
    } catch (\Taxmod\Core\Exception\ModelDoorRefused) {
        return true;
    }

    return false;
};

check('a plain number is refused', $refused(static fn () => $door->putAmount($record, $n, 1.0)));
check('two units without a conversion are refused', $refused(static fn () => $door->putAmount($record, $g, 1.0)));

echo $bad === 0 ? "\nAll checks passed.\n" : "\n$bad check(s) FAILED.\n";
exit($bad === 0 ? 0 : 1);
