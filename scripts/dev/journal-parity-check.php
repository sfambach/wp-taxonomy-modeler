<?php declare(strict_types=1);

/**
 * The same sequence of acts writes the same chronicle rows — row for row — as before the model started to send events
 * (renderer plan step 3, D-1424, [D-631](../../docs/NewConcept/90-decision-log.md)).
 *
 * ```mermaid
 * flowchart LR
 *   S["fixed sequence through taxmod/apply"] --> C["changelog rows of this run"] --> N["ids → names"] --> V{"= lib/journal-parity.txt"}
 * ```
 *
 * ⚠️ *The reference file was written by this check on the code **before** step 3 (`--write`), on 2026-11-17 — the plan's
 * «frisch erzeugter Vergleichslauf», because the owner's chronicle is no reference (D-634). Ids differ from run to run, so
 * every id this run created is replaced by its name, and the group numbers by their order of appearance.*
 *
 *     php scripts/dev/journal-parity-check.php [--write]
 */

define('WP_USE_THEMES', false);

$root = getenv('WP_ROOT') ?: '';

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Set WP_ROOT to the WordPress folder.\n");
    exit(2);
}

require $root . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Plugin;

const REFERENCE = __DIR__ . '/lib/journal-parity.txt';

add_filter(Plugin::NODE_PROPERTIES_FILTER, static fn (array $declared): array => $declared + ['jp_flag' => 'Journal parity flag']);

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 1));

$plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($plugin, 'file'))->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$model = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin)->rootOf(Branch::Model);
$text  = (new WpdbNodeRepository())->byImplementations([TextType::class])[TextType::class] ?? null;

global $wpdb;
$table = Schema::table('changelog');
$start = (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) FROM {$table}");

$apply = static function (array $changes): array {
    $antwort = wp_get_ability('taxmod/apply')->execute(['changes' => $changes]);

    if (is_wp_error($antwort)) {
        fwrite(STDERR, 'apply refused: ' . $antwort->get_error_message() . "\n");
        exit(1);
    }

    return $antwort['refs'] ?? [];
};

// ── The sequence: one of each kind the four model events cover ──────────────
$r = $apply([
    ['op' => 'create_node', 'ref' => 'a', 'name' => '__jp Gruppe', 'parent' => $model->id],
    ['op' => 'create_node', 'ref' => 'b', 'name' => '__jp Kind', 'parent' => '@a'],
    ['op' => 'create_node', 'ref' => 'c', 'name' => '__jp Enkel', 'parent' => '@b'],
    ['op' => 'add_field', 'ref' => 'f', 'node' => '@a', 'target' => $text?->id ?? 0, 'name' => '__jp Farbe'],
    ['op' => 'add_field', 'ref' => 'g', 'node' => '@a', 'target' => $text?->id ?? 0, 'name' => '__jp Notiz'],
    ['op' => 'create_record', 'ref' => 'r', 'node' => '@b', 'values' => ['@f' => 'rot']],
]);
$apply([['op' => 'rename_node', 'node' => $r['b'], 'name' => '__jp Kind neu']]);
$apply([['op' => 'rename_field', 'node' => $r['a'], 'field' => $r['f'], 'name' => '__jp Farbton']]);
$apply([['op' => 'set_multiplicity', 'node' => $r['a'], 'field' => $r['f'], 'multiplicity' => '0..*']]);
$apply([['op' => 'hide_field', 'node' => $r['a'], 'field' => $r['g'], 'hidden' => true]]);
$apply([['op' => 'set_label', 'node' => $r['b'], 'role' => 'help', 'text' => '__jp Hilfe']]);
$apply([['op' => 'set_setting', 'node' => $r['a'], 'attribute' => 'jp_flag', 'value' => '1']]);
$apply([['op' => 'set_values', 'record' => $r['r'], 'values' => [(string) $r['f'] => 'blau']]]);
$k = $apply([['op' => 'duplicate_record', 'ref' => 'k', 'record' => $r['r']]]);
$apply([['op' => 'remove_record', 'record' => $k['k']]]);
$apply([['op' => 'move_node', 'node' => $r['c'], 'parent' => $r['a']]]);
$apply([['op' => 'remove_field', 'node' => $r['a'], 'field' => $r['g']]]);
$apply([['op' => 'trash_node', 'node' => $r['b']]]);

// ── Read back, ids replaced by names ────────────────────────────────────────
$namen = [];

foreach (['a' => 'GRUPPE', 'b' => 'KIND', 'c' => 'ENKEL', 'f' => 'FARBE', 'g' => 'NOTIZ', 'r' => 'SATZ'] as $ref => $name) {
    $namen[(int) $r[$ref]] = $name;
}

$namen[(int) $k['k']] = 'KOPIE';
$namen[(int) $model->id] = 'MODEL';

$zeilen = $wpdb->get_results($wpdb->prepare(
    "SELECT change_group_id, owner_id, owner_kind, what, version, before_state, after_state FROM {$table} WHERE id > %d ORDER BY id",
    $start
), ARRAY_A) ?: [];

// Every further id the run met (values, edge records): named by the order it appears in.
$fremd  = [];
$gruppe = [];
krsort($namen);

$ersetzen = static function (?string $text) use (&$namen, &$fremd): string {
    if ($text === null) {
        return '∅';
    }

    // *A path is a chain of sort keys, not of ids, and they differ per database: what stays is its depth.*
    $text = (string) preg_replace_callback('/path=([0-9.]+)/', static fn (array $m): string => 'path=' . implode('.', array_fill(0, substr_count($m[1], '.') + 1, '·')), $text);
    // *The locale of a label is the site's (D-645), not the act's.*
    $text = (string) preg_replace('/locale=[a-z]{2}_[A-Z]{2}/', 'locale=·', $text);

    return (string) preg_replace_callback('/(?<![0-9.])[0-9]{3,}(?![0-9.])/', static function (array $m) use (&$namen, &$fremd): string {
        $id = (int) $m[0];

        return $namen[$id] ?? ($fremd[$id] ??= 'ID' . (count($fremd) + 1));
    }, preg_replace('/"(at|by_user_id|created_at|updated_at)":"?[^,"}]*"?/', '"$1":·', $text));
};

$aus = [];

foreach ($zeilen as $zeile) {
    $gruppe[$zeile['change_group_id']] ??= 'G' . (count($gruppe) + 1);
    $aus[] = implode(' | ', [
        $gruppe[$zeile['change_group_id']],
        $zeile['owner_kind'],
        $ersetzen((string) $zeile['owner_id']),
        $zeile['what'],
        'v' . ($zeile['version'] ?? '∅'),
        $ersetzen($zeile['before_state']),
        $ersetzen($zeile['after_state']),
    ]);
}

$jetzt = implode("\n", $aus) . "\n";

if (in_array('--write', $argv, true)) {
    file_put_contents(REFERENCE, $jetzt);
    printf("%d rows written to %s\n", count($aus), basename(REFERENCE));
    exit(0);
}

$soll = is_readable(REFERENCE) ? (string) file_get_contents(REFERENCE) : '';
$sollZeilen = $soll === '' ? [] : explode("\n", rtrim($soll, "\n"));

printf("%d rows now, %d in the reference\n", count($aus), count($sollZeilen));

$fehler = 0;

foreach ($sollZeilen as $i => $erwartet) {
    if (($aus[$i] ?? null) !== $erwartet) {
        ++$fehler;
        printf("  FAIL row %d\n    was: %s\n    now: %s\n", $i + 1, $erwartet, $aus[$i] ?? '(missing)');
    }
}

for ($i = count($sollZeilen); $i < count($aus); ++$i) {
    ++$fehler;
    printf("  FAIL row %d is new: %s\n", $i + 1, $aus[$i]);
}

if ($sollZeilen === [] || count($aus) < 10) {
    echo "  FAIL the run wrote too little to compare\n";
    exit(1);
}

echo $fehler === 0 ? "Row for row the same.\n" : "$fehler FAILED\n";

exit($fehler === 0 ? 0 : 1);
