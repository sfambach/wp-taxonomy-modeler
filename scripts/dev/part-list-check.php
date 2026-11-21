<?php declare(strict_types=1);
/**
 * A list of record ids written into a field of parts is refused by name, never reported as done with nothing written (CD-10).
 *
 *     php scripts/dev/part-list-check.php [path/to/wordpress]
 *
 * ⚠️ *Prüftor 21.11.: set_values with [held part, @neu] on the conditions of rule 87580 answered fits true and changed nothing
 * (act 608123). Measured at the result: the same op is refused, nothing is written, and the documented form still adds a part.*
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
use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\ModelDoor;
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

$plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($plugin, 'file'))->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$model = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin)->rootOf(Branch::Model);
$text  = (new WpdbNodeRepository())->byImplementations([TextType::class])[TextType::class] ?? null;
$apply = static fn (array $changes): mixed => wp_get_ability('taxmod/apply')->execute(['changes' => $changes]);
$door  = new ModelDoor();

$gebaut = $apply([
    ['op' => 'create_node', 'ref' => 'z', 'name' => '__pl Bedingung', 'parent' => $model->id],
    ['op' => 'add_field', 'ref' => 't', 'node' => '@z', 'target' => $text?->id ?? 0, 'name' => '__pl Text'],
    ['op' => 'create_node', 'ref' => 'e', 'name' => '__pl Regel', 'parent' => $model->id],
    ['op' => 'add_field', 'ref' => 'f', 'node' => '@e', 'target' => '@z', 'name' => '__pl Bedingungen', 'kind' => 'composition', 'multiplicity' => '0..*'],
    ['op' => 'create_record', 'ref' => 'r', 'node' => '@e'],
]);

check('the test model is built', is_array($gebaut), is_wp_error($gebaut) ? $gebaut->get_error_message() : '');

if (! is_array($gebaut)) {
    exit(1);
}

$r      = $gebaut['refs'];
[$f, $t, $record] = [(string) $r['f'], (string) $r['t'], (int) $r['r']];
$teile  = static fn (): array => $door->valuesOf([$record])[$record][(int) $f] ?? [];

$apply([['op' => 'set_values', 'record' => (string) $record, 'values' => [$f => ['neu' => [$t => 'eins']]]]]);
$vorher = $teile();
check('a part is written in the documented form', count($vorher) === 1, json_encode($vorher));

$liste = $apply([
    ['op' => 'create_record', 'ref' => 'neu', 'node' => (string) $r['z'], 'values' => [$t => 'zwei']],
    ['op' => 'set_values', 'record' => (string) $record, 'values' => [$f => [$vorher[0] ?? '0', '@neu']]],
]);
check('a list of record ids is refused', is_wp_error($liste), is_wp_error($liste) ? '' : json_encode($liste));
check('naming the field and the way that works', is_wp_error($liste) && str_contains($liste->get_error_message(), '__pl Bedingungen') && str_contains($liste->get_error_message(), 'move_part'), is_wp_error($liste) ? $liste->get_error_message() : '');
check('and nothing is written', $teile() === $vorher, json_encode($teile()));

$apply([['op' => 'set_values', 'record' => (string) $record, 'values' => [$f => ['zweite' => [$t => 'zwei']]]]]);
check('a new key still adds a second part', count($teile()) === 2, json_encode($teile()));

echo $bad === 0 ? "\nAll checks passed.\n" : "\n$bad check(s) FAILED.\n";
exit($bad === 0 ? 0 : 1);
