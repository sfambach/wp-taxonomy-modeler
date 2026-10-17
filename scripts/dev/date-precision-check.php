<?php declare(strict_types=1);
/**
 * Ein Datum merkt sich je Wert, wie genau es bekannt ist — und zeigt sich so (D-932).
 *
 *     php scripts/dev/date-precision-check.php [path/to/wordpress]
 *
 * ```mermaid
 * flowchart LR
 *   E["«1994», «1997-03», «1997-03-07»"] --> S["gespeichert: erster Tag + Genauigkeit"]
 *   S --> V["Vergleich: «1994», «1997-03», «1997-03-07»"]
 *   S --> F["Formular schickt denselben Zeitpunkt"] --> B["Genauigkeit bleibt"]
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md
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

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — siehe `lib/no-write.php`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Type\DateTimeType;
use Taxmod\WordPress\Persistence\Schema;
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
$tree  = wp_get_ability('taxmod/read-tree');
$datum = (new WpdbNodeRepository())->byImplementations([DateTimeType::class])[DateTimeType::class] ?? null;
$baum  = $tree?->execute(['depth' => 1]);

check('der Typknoten Datum ist da', $datum !== null);

echo "Speichern (D-932)\n";

$ergebnis = $apply?->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'k', 'name' => '__dp Karte', 'parent' => is_array($baum) ? $baum['root'] : 0],
    ['op' => 'add_field', 'ref' => 'f', 'node' => '@k', 'target' => $datum?->id ?? 0, 'name' => '__dp Erschienen', 'multiplicity' => '0..1'],
    ['op' => 'create_record', 'ref' => 'jahr', 'node' => '@k'],
    ['op' => 'create_record', 'ref' => 'monat', 'node' => '@k'],
    ['op' => 'create_record', 'ref' => 'tag', 'node' => '@k'],
    ['op' => 'set_values', 'record' => '@jahr', 'values' => ['@f' => '1994']],
    ['op' => 'set_values', 'record' => '@monat', 'values' => ['@f' => '1997-03']],
    ['op' => 'set_values', 'record' => '@tag', 'values' => ['@f' => '1997-03-07']],
]]);

check('apply nimmt drei Genauigkeiten an', is_array($ergebnis), is_wp_error($ergebnis) ? $ergebnis->get_error_message() : '');

if (! is_array($ergebnis)) {
    echo "\n$ok ok, $bad failed\n";
    exit(1);
}

$r       = $ergebnis['refs'];
$zeilen  = $wpdb->get_results($wpdb->prepare(
    'SELECT node_record_id, value_date, value_precision FROM ' . Schema::table('relation_records') . ' WHERE relation_id = %d',
    $r['f']
), OBJECT_K);

check('«1994» steht als erster Januar mit der Genauigkeit Jahr', ($zeilen[$r['jahr']]->value_date ?? '') === '1994-01-01 00:00:00' && ($zeilen[$r['jahr']]->value_precision ?? '') === 'year', wp_json_encode($zeilen[$r['jahr']] ?? null));
check('«1997-03» steht als Monatserster mit der Genauigkeit Monat', ($zeilen[$r['monat']]->value_date ?? '') === '1997-03-01 00:00:00' && ($zeilen[$r['monat']]->value_precision ?? '') === 'month', wp_json_encode($zeilen[$r['monat']] ?? null));
check('ein ganzer Tag hat keine Angabe — ganz bekannt, wie bisher', ($zeilen[$r['tag']]->value_date ?? '') === '1997-03-07 00:00:00' && isset($zeilen[$r['tag']]) && $zeilen[$r['tag']]->value_precision === null, wp_json_encode($zeilen[$r['tag']] ?? null));

echo "Anzeigen\n";

wp_set_current_user(0);
$vergleich = wp_strip_all_tags(do_blocks('<!-- wp:taxmod/record ' . wp_json_encode([
    'display'   => 'comparison',
    'record'    => $r['jahr'],
    'records'   => implode(', ', [$r['jahr'], $r['monat'], $r['tag']]),
    'rowFields' => (string) $r['f'],
]) . ' /-->'));
$einzeln   = wp_strip_all_tags(do_blocks('<!-- wp:taxmod/record {"record":' . $r['jahr'] . ',"field":' . $r['f'] . '} /-->'));
wp_set_current_user((int) ($admin[0] ?? 1));

check('der Vergleich zeigt «1994», «1997-03», «1997-03-07»', str_contains($vergleich, '1994') && ! str_contains($vergleich, '1994-01') && str_contains($vergleich, '1997-03') && str_contains($vergleich, '1997-03-07') && ! str_contains($vergleich, '1997-03-01'), trim($vergleich));
check('der Block mit einem Feld zeigt «1994»', str_contains($einzeln, '1994') && ! str_contains($einzeln, '1994-01'), trim($einzeln));

echo "Formular\n";

// ⚠️ *Ein Eingabefeld für einen ganzen Zeitpunkt schickt den ersten Januar zurück — das darf das Jahr nicht zum Tag machen.*
$plugin = (new ReflectionClass(\Taxmod\WordPress\Plugin::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($plugin, 'file'))->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
$satz   = $screen->entries()->find($r['jahr']);
$screen->writeRecord($satz->nodeId, $r['jahr'], wp_slash([(string) $r['f'] => '1994-01-01T00:00']), null, null, \Taxmod\WordPress\Admin\SettingsScreen::neutralLocale());
$danach = $wpdb->get_var($wpdb->prepare(
    'SELECT value_precision FROM ' . Schema::table('relation_records') . ' WHERE relation_id = %d AND node_record_id = %d',
    $r['f'],
    $r['jahr']
));
check('dasselbe Datum aus dem Formular lässt die Genauigkeit Jahr stehen', $danach === 'year', var_export($danach, true));

$screen->writeRecord($satz->nodeId, $r['jahr'], wp_slash([(string) $r['f'] => '1995-06-12T00:00']), null, null, \Taxmod\WordPress\Admin\SettingsScreen::neutralLocale());
$neu = $wpdb->get_row($wpdb->prepare(
    'SELECT value_date, value_precision FROM ' . Schema::table('relation_records') . ' WHERE relation_id = %d AND node_record_id = %d',
    $r['f'],
    $r['jahr']
));
check('ein anderes Datum aus dem Formular ersetzt den Wert ganz', ($neu->value_date ?? '') === '1995-06-12 00:00:00' && is_object($neu) && $neu->value_precision === null, wp_json_encode($neu));

echo "\n$ok ok, $bad failed\n";
exit($bad === 0 ? 0 : 1);
