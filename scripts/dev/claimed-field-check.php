<?php declare(strict_types=1);
/**
 * A module claims a field of a record (D-1334): the record editor shows it read-only with the module's button, and a save
 * through the form leaves it alone.
 *
 *     WP_ROOT=/path/to/wordpress php scripts/dev/claimed-field-check.php
 *
 * @see docs/gestaltung-und-architektur.md S18
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

$built = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'n', 'name' => '__cf Platz', 'parent' => $model->id],
    ['op' => 'add_field', 'ref' => 'kind', 'node' => '@n', 'target' => $text?->id ?? 0, 'name' => '__cf Art'],
    ['op' => 'add_field', 'ref' => 'note', 'node' => '@n', 'target' => $text?->id ?? 0, 'name' => '__cf Notiz'],
    ['op' => 'create_record', 'ref' => 'r', 'node' => '@n'],
    ['op' => 'set_values', 'record' => '@r', 'values' => ['@kind' => 'Regal', '@note' => 'alt']],
]]);

check('the test record is built', is_array($built), is_wp_error($built) ? $built->get_error_message() : '');

if (! is_array($built)) {
    exit(1);
}

['n' => $node, 'kind' => $kind, 'note' => $note, 'r' => $record] = $built['refs'];

add_filter(Plugin::CLAIMED_FIELDS_FILTER, static fn (array $claims, int $recordId): array => $recordId === $record
    ? $claims + [$kind => ['label' => 'Art wechseln …', 'url' => 'https://example.test/dialog?r=' . $record], 999999 => ['label' => 'x', 'url' => 'https://example.test/']]
    : $claims, 10, 2);

$editor = (string) apply_filters(Plugin::RECORD_EDITOR_FILTER, '', $record, admin_url('admin.php?page=x'), []);
check('the claimed field shows the module button', str_contains($editor, 'taxmod-claimed-field') && str_contains($editor, 'example.test/dialog'), substr($editor, 0, 300));
check('the claimed field shows its value', str_contains($editor, 'Regal'));
check('the claimed field has no control', ! str_contains($editor, 'taxmod_value[' . $record . '][' . $kind . ']'));
check('the other field keeps its control', str_contains($editor, 'taxmod_value[' . $record . '][' . $note . ']'));
check('a claim on a field the node lacks is ignored', ! str_contains($editor, 'https://example.test/"'));

$screen = $plugin->screen();
$_POST  = ['node_record_id' => (string) $record, 'taxmod_value' => [$record => [$kind => 'Box', $note => 'neu']]];
(new ReflectionMethod($screen, 'saveRecord'))->invoke($screen, $node);
$_POST = [];

$values = [];

foreach (wp_get_ability('taxmod/read-node')->execute(['node' => $node])['records'] ?? [] as $row) {
    if ((int) ($row['id'] ?? 0) === $record) {
        $values = $row;
    }
}

$encoded = (string) wp_json_encode($values);
check('a form save leaves the claimed field alone', str_contains($encoded, 'Regal') && ! str_contains($encoded, 'Box'), $encoded);
check('a form save writes the other field', str_contains($encoded, 'neu'), $encoded);

echo $bad === 0 ? "\nall green\n" : "\n$bad failed\n";
exit($bad === 0 ? 0 : 1);
