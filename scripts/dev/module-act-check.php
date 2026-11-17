<?php declare(strict_types=1);
/**
 * A module's several writes are one act: one number, one undo, whole or not at all (D-1419, D-1410).
 *
 *     php scripts/dev/module-act-check.php [path/to/wordpress]
 *
 * ⚠️ *Measured at the result: two `write-data` calls inside `ModelDoor::inAct()` must name the same `act`, one `undo_act`
 * must bring the model back, and a failure in the second write must leave nothing of the first.*
 *
 * @see docs/NewConcept/90-decision-log.md D-1419
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

$gebaut = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'a', 'name' => '__ma Teile', 'parent' => $model->id],
    ['op' => 'add_field', 'ref' => 'f', 'node' => '@a', 'target' => $text?->id ?? 0, 'name' => '__ma Farbe'],
    ['op' => 'create_record', 'ref' => 'r', 'node' => '@a', 'values' => ['@f' => 'rot']],
    ['op' => 'create_record', 'ref' => 's', 'node' => '@a', 'values' => ['@f' => 'blau']],
]]);

check('the test records are built', is_array($gebaut), is_wp_error($gebaut) ? $gebaut->get_error_message() : '');

if (! is_array($gebaut)) {
    exit(1);
}

$r     = $gebaut['refs'];
$field = (string) $r['f'];
$door  = new ModelDoor();
$value = static fn (int $record): string => (string) ($door->valuesOf([$record])[$record][(int) $field][0] ?? '');

echo "\n== 1. Two writes, one number ==\n";

$answer = ModelDoor::inAct(static function () use ($door, $r, $field): array {
    $first  = $door->write([['op' => 'set_values', 'record' => $r['r'], 'values' => [$field => 'gelb']]]);
    $second = $door->write([['op' => 'set_values', 'record' => $r['s'], 'values' => [$field => 'grün']]]);

    return [(int) ($first['act'] ?? 0), (int) ($second['act'] ?? 0)];
});

[$first, $second] = $answer['result'];
check('the bracket names an act', (int) $answer['act'] > 0);
check('both writes name that act', $first === (int) $answer['act'] && $second === (int) $answer['act'], "$first / $second / {$answer['act']}");
check('both values stand', $value($r['r']) === 'gelb' && $value($r['s']) === 'grün', $value($r['r']) . ' / ' . $value($r['s']));

$undo = $door->undo((int) $answer['act']);
check('one undo takes it back', (int) ($undo['act'] ?? 0) > 0);
check('both values are as before', $value($r['r']) === 'rot' && $value($r['s']) === 'blau', $value($r['r']) . ' / ' . $value($r['s']));

echo "\n== 2. Whole or not at all ==\n";

$failed = '';

try {
    ModelDoor::inAct(static function () use ($door, $r, $field): void {
        $door->write([['op' => 'set_values', 'record' => $r['r'], 'values' => [$field => 'lila']]]);
        $door->write([['op' => 'set_values', 'record' => 999999999, 'values' => [$field => 'nichts']]]);
    });
} catch (Throwable $e) {
    $failed = $e->getMessage();
}

check('a refused second write throws out of the bracket', $failed !== '');
check('and the first write is rolled back', $value($r['r']) === 'rot', $value($r['r']));

$after = $door->write([['op' => 'set_values', 'record' => $r['s'], 'values' => [$field => 'weiss']]]);
check('a write after the bracket has its own act again', (int) ($after['act'] ?? 0) > (int) $answer['act']);

echo $bad === 0 ? "\nAll checks passed.\n" : "\n$bad check(s) FAILED.\n";
exit($bad === 0 ? 0 : 1);
