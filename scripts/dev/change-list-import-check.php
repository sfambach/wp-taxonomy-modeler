<?php declare(strict_types=1);
/**
 * Änderungslisten als Datei einspielen — jede Datei eine Handlung, eine eingespielte wird übersprungen, «@ref» gilt über die Datei hinaus (D-973).
 *
 *     php scripts/dev/change-list-import-check.php [path/to/wordpress]
 *
 * ```mermaid
 * flowchart LR
 *   Z["ZIP mit zwei Listen"] --> L["nach Namen gelesen"]
 *   L --> A["beide eingespielt, @ref aus Datei 1 gilt in Datei 2"]
 *   A --> W["noch einmal: beide übersprungen"]
 *   W --> F["eine kaputte dritte: Fehler mit Dateiname, nichts von ihr bleibt"]
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

use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\Admin\ChangeListImport;

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

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 1));

$plugin = (new ReflectionClass(\Taxmod\WordPress\Plugin::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($plugin, 'file'))->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$import = new ChangeListImport($plugin->abilities(...));
$nodes  = new \Taxmod\WordPress\Persistence\WpdbNodeRepository();
$text   = $nodes->byImplementations([TextType::class])[TextType::class] ?? null;
$wurzel = (int) (wp_get_ability('taxmod/read-tree')?->execute(['depth' => 1])['root'] ?? 0);

check('der Typknoten Text und die Wurzel sind da', $text !== null && $wurzel > 0);

update_option(ChangeListImport::DONE_OPTION, [], false);

$eins = (string) wp_json_encode(['changes' => [
    ['op' => 'create_node', 'ref' => 'cl_knoten', 'name' => '__cl Knoten', 'parent' => $wurzel],
    ['op' => 'add_field', 'ref' => 'cl_feld', 'node' => '@cl_knoten', 'target' => $text?->id ?? 0, 'name' => '__cl Titel'],
]]);
$zwei = (string) wp_json_encode(['changes' => [
    ['op' => 'create_record', 'ref' => 'cl_satz', 'node' => '@cl_knoten'],
    ['op' => 'set_values', 'record' => '@cl_satz', 'values' => ['@cl_feld' => 'aus Datei zwei']],
]]);

echo "ZIP lesen\n";

$datei = (string) tempnam(sys_get_temp_dir(), 'taxmod-cl');
$zip   = new ZipArchive();
$zip->open($datei, ZipArchive::OVERWRITE);
$zip->addFromString('paket/02-saetze.json', $zwei);
$zip->addFromString('paket/01-struktur.json', $eins);
$zip->addFromString('paket/liesmich.txt', 'kein JSON');
$zip->close();

$listen = $import->lists($datei, 'paket.zip');
@unlink($datei);

ksort($listen);
check('nur die .json-Dateien kommen an', array_keys($listen) === ['paket/01-struktur.json', 'paket/02-saetze.json'], implode(', ', array_keys($listen)));

echo "Einspielen\n";

$bericht = $import->run($listen, false);
check('beide Dateien sind eingespielt, nach Namen geordnet', ($bericht['applied'] ?? null) === ['paket/01-struktur.json', 'paket/02-saetze.json'], (string) wp_json_encode($bericht));

$knoten  = (int) ($bericht['applied'] !== [] ? array_column(wp_get_ability('taxmod/read-tree')?->execute(['depth' => 1])['rows'] ?? [], 'id', 'name')['__cl Knoten'] ?? 0 : 0);
$gelesen = wp_get_ability('taxmod/read-node')?->execute(['node' => $knoten]);
$werte   = is_array($gelesen) ? array_column($gelesen['records'][0]['values'] ?? [], 'value') : [];
check('«@ref» aus Datei eins gilt in Datei zwei', in_array('aus Datei zwei', $werte, true), (string) wp_json_encode($werte));

$vermerkt = get_option(ChangeListImport::DONE_OPTION, []);
check('jede eingespielte Datei ist mit ihrem Fingerabdruck vermerkt', is_array($vermerkt) && isset($vermerkt[sha1($eins)], $vermerkt[sha1($zwei)]));

echo "Wiederholen\n";

$nochmal = $import->run($listen, false);
check('noch einmal hochgeladen: nichts doppelt, beide übersprungen', ($nochmal['applied'] ?? null) === [] && ($nochmal['skipped'] ?? 0) === 2 && ($nochmal['remaining'] ?? 1) === 0, (string) wp_json_encode($nochmal));

$drei = (string) wp_json_encode(['changes' => [
    ['op' => 'create_record', 'ref' => 'cl_x', 'node' => '@cl_knoten'],
    ['op' => 'set_values', 'record' => '@cl_x', 'values' => ['@gibt_es_nicht' => 'x']],
]]);
$saetze  = count($gelesen['records'] ?? []);
$fehler  = $import->run($listen + ['paket/03-kaputt.json' => $drei], false);
$danach  = wp_get_ability('taxmod/read-node')?->execute(['node' => $knoten]);

check('eine kaputte Liste meldet ihren Dateinamen', str_starts_with((string) ($fehler['error'] ?? ''), 'paket/03-kaputt.json'), (string) wp_json_encode($fehler));
check('die frühere Datei hat ihre Refs mitgegeben, scheitern tut nur das Unbekannte', str_contains((string) ($fehler['error'] ?? ''), '@gibt_es_nicht'), (string) ($fehler['error'] ?? ''));
check('von der kaputten Liste bleibt nichts', count($danach['records'] ?? []) === $saetze);
check('keine Liste ohne «changes»', (static function () use ($import): bool {
    try {
        $import->run(['x.json' => '{"nichts": 1}'], false);
    } catch (\RuntimeException $e) {
        return str_contains($e->getMessage(), 'x.json');
    }

    return false;
})());

echo "\n$ok ok, $bad failed\n";
exit($bad === 0 ? 0 : 1);
