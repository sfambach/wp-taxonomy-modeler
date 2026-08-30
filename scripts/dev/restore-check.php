<?php declare(strict_types=1);
/**
 * Zurücksetzen auf den vorigen Stand — an echten Zeilen, und wieder umkehrbar.
 *
 *     php scripts/dev/restore-check.php [path/to/wordpress]
 *
 * ⚠️ **Das ist die Belohnung für [D-536](../../docs/NewConcept/90-decision-log.md)**, und sein Satz
 * ist die Zusage: *«wenn ich zurück will, dann drehe ich einfach die aktuelle Version und habe die
 * Vorgängerversion wieder verfügbar».*
 *
 * ⚠️ **Vorwärts und nicht zurückgespult** ([D-172](../../docs/NewConcept/90-decision-log.md)): *das
 * Zurücksetzen ist selbst eine neue Version. **Darum prüft dieser Lauf, dass ein zweites
 * Zurücksetzen die erste Umkehrung wieder umkehrt** — eine Geschichte, die beim Zurückgehen kürzer
 * wird, kann man nur einmal benutzen.*
 *
 * ⚠️ *Alles an einem eigens angelegten Datensatz, der am Ende samt seiner Geschichte verschwindet —
 * auch bei einem Absturz.*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
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
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\EdgeRecord;
use Taxmod\Core\Model\NodeRecord;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Persistence\Restore;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;

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

$vorlage = $wpdb->get_row('SELECT record_id, edge_id FROM ' . Schema::table('record_values') . ' LIMIT 1');

if ($vorlage === null) {
    check('eine Wertzeile als Vorlage gefunden', false, 'record_values ist leer');

    echo "\n1 fehlgeschlagen, 0 in Ordnung\n";

    exit(1);
}

$knotenId = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT node_id FROM ' . Schema::table('records') . ' WHERE id = %d',
    (int) $vorlage->record_id
));

$records = new WpdbRecordRepository();
$satzId  = $records->add(new NodeRecord(0, $knotenId, 1, '2026-08-30 00:00:00'));

register_shutdown_function(static function () use ($satzId): void {
    global $wpdb;

    foreach (['record_values', 'records'] as $t) {
        $spalte = $t === 'records' ? 'id' : 'record_id';
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table($t) . " WHERE {$spalte} = %d", $satzId));
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table($t . '_history') . " WHERE {$spalte} = %d",
            $satzId
        ));
    }
});

/** Der aktuelle Text und die aktuelle Version der einen Wertzeile, oder null. */
function jetzt(int $satzId): ?array
{
    global $wpdb;

    $z = $wpdb->get_row($wpdb->prepare(
        'SELECT value_text, version FROM ' . Schema::table('record_values') . ' WHERE record_id = %d',
        $satzId
    ), ARRAY_A);

    return $z === null ? null : ['text' => (string) $z['value_text'], 'version' => (int) $z['version']];
}

echo "\n== 1. Drei Staende schreiben ==\n";

$records->putValue(EdgeRecord::direct($satzId, (int) $vorlage->edge_id, TypedValue::ofText('erster')));
$zeile = $records->valuesOf($satzId)[0];

foreach (['zweiter', 'dritter'] as $text) {
    $records->putValue(new EdgeRecord(
        $satzId,
        $zeile->path,
        (int) $vorlage->edge_id,
        '',
        TypedValue::ofText($text),
        $zeile->id,
        $zeile->position
    ));
}

$stand = jetzt($satzId);

check('lebend steht der dritte', ($stand['text'] ?? null) === 'dritter', $stand['text'] ?? 'nichts');
check('und die Version ist gewachsen', ($stand['version'] ?? 0) === 3, (string) ($stand['version'] ?? 0));

$verlauf = $wpdb->get_col($wpdb->prepare(
    'SELECT value_text FROM ' . Schema::table('record_values_history') . '
     WHERE record_id = %d ORDER BY version ASC',
    $satzId
));

check('der Schatten hält beide Vorgänger', $verlauf === ['erster', 'zweiter'], implode(',', $verlauf));

echo "\n== 2. Einmal zurueck ==\n";

check('es gab etwas zurueckzusetzen', Restore::previous('record_values', (int) $zeile->id));

$stand = jetzt($satzId);

check('lebend steht wieder der zweite', ($stand['text'] ?? null) === 'zweiter', $stand['text'] ?? 'nichts');

// ⚠️ **Vorwärts, nicht zurückgespult** ([D-172](../../docs/NewConcept/90-decision-log.md)): *die
// Version ist **4**, nicht wieder 2. Wäre sie 2, stünden zwei verschiedene Inhalte unter derselben
// Version und der Schatten hätte keinen eindeutigen Schlüssel mehr.*
check('und die Version ist weitergezaehlt, nicht zurueckgedreht', ($stand['version'] ?? 0) === 4, (string) ($stand['version'] ?? 0));

echo "\n== 3. Und das Zurueck ist selbst umkehrbar ==\n";

check('noch einmal zurueck geht', Restore::previous('record_values', (int) $zeile->id));

$stand = jetzt($satzId);

// ⚠️ *Der Vorgänger von Version 4 ist der Stand, der bei ihrem Entstehen aufgehoben wurde — «dritter».
// **Damit kehrt das zweite Zurücksetzen das erste um**, und die Geschichte wird dabei länger statt
// kürzer.*
check('der dritte ist wieder da', ($stand['text'] ?? null) === 'dritter', $stand['text'] ?? 'nichts');
check('als Version 5', ($stand['version'] ?? 0) === 5, (string) ($stand['version'] ?? 0));

echo "\n== 4. Ein geloeschter Stand kommt zurueck ==\n";

$records->forgetValueById((int) $zeile->id);

check('lebend ist nichts mehr', jetzt($satzId) === null);
check('zuruecksetzen holt ihn', Restore::previous('record_values', (int) $zeile->id));

$stand = jetzt($satzId);

check('und es ist der Stand vor dem Loeschen', ($stand['text'] ?? null) === 'dritter', $stand['text'] ?? 'nichts');

echo "\n== 5. Was keinen Vorgaenger hat, sagt das ==\n";

$frisch = $records->add(new NodeRecord(0, $knotenId, 1, '2026-08-30 00:00:00'));

register_shutdown_function(static function () use ($frisch): void {
    global $wpdb;

    $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('records') . ' WHERE id = %d', $frisch));
    $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('records_history') . ' WHERE id = %d', $frisch));
});

// ⚠️ *`false` und keine Ausnahme: «diese Zeile hatte nie einen Vorgänger» ist eine Antwort, kein
// Fehler. Eine Ausnahme hier zwänge jeden Aufrufer, den Normalfall abzufangen.*
check('ein frischer Datensatz meldet «nichts zurueckzusetzen»', Restore::previous('records', $frisch) === false);

check(
    'und er steht unveraendert da',
    (int) $wpdb->get_var($wpdb->prepare('SELECT version FROM ' . Schema::table('records') . ' WHERE id = %d', $frisch)) === 1
);

echo "\n== 6. Eine ganze Gruppe, und der Waechter davor ==\n";

$journal = Schema::table('changelog');
$knoten  = Schema::table('nodes');

$probe = $wpdb->get_row("SELECT id, version FROM {$knoten} ORDER BY id DESC LIMIT 1");

if ($probe === null) {
    check('einen Knoten als Probe gefunden', false, 'nodes ist leer');
} else {
    check('einen Knoten als Probe gefunden', true);

    $vorher = (int) $probe->version;

    // ⚠️ *Der Knoten wird gleich zurückgesetzt, also muss sein jetziger Stand vorher in den Schatten —
    // sonst hat `previous()` nichts zu holen und die Prüfung wäre grün, weil sie nichts fand.*
    \Taxmod\WordPress\Persistence\Shadow::keepOne('nodes', (int) $probe->id);

    $wpdb->query($wpdb->prepare(
        "UPDATE {$knoten} SET version = version + 1 WHERE id = %d",
        (int) $probe->id
    ));

    // Eine Journalzeile, wie ein Akt sie schreiben würde — mit der Version, die er erzeugt hat.
    $wpdb->insert($journal, [
        'change_group_id' => null,
        'owner_id'        => (int) $probe->id,
        'owner_kind'      => 'node',
        'at'              => '2026-08-30 00:00:00',
        'what'            => 'probe',
        'version'         => $vorher + 1,
    ], ['%d', '%d', '%s', '%s', '%s', '%d']);

    $gruppe = (int) $wpdb->insert_id;
    $wpdb->query($wpdb->prepare("UPDATE {$journal} SET change_group_id = %d WHERE id = %d", $gruppe, $gruppe));

    register_shutdown_function(static function () use ($gruppe, $probe): void {
        global $wpdb;

        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('changelog') . ' WHERE change_group_id = %d', $gruppe));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('nodes_history') . ' WHERE id = %d', (int) $probe->id));
    });

    // ⚠️ **Erst der Wächter.** *Jemand anders schreibt nach dem Akt — dann darf die Gruppe **nicht**
    // zurückgesetzt werden, sonst trifft sie fremde Arbeit ([OQ-137](../../docs/NewConcept/91-open-questions.md)).*
    $wpdb->query($wpdb->prepare("UPDATE {$knoten} SET version = version + 1 WHERE id = %d", (int) $probe->id));

    $bericht = Restore::group($gruppe);

    check('nach fremdem Schreiben wird verweigert', $bericht['restored'] === [] && count($bericht['refused']) === 1);
    check(
        'und die Verweigerung sagt warum',
        str_contains($bericht['refused'][0]['why'] ?? '', 'seither wurde geschrieben'),
        $bericht['refused'][0]['why'] ?? 'keine Begründung'
    );

    // Fremde Schreibung zurücknehmen, dann darf die Gruppe.
    $wpdb->query($wpdb->prepare("UPDATE {$knoten} SET version = %d WHERE id = %d", $vorher + 1, (int) $probe->id));

    $bericht = Restore::group($gruppe);

    check('ohne fremde Schreibung geht die Gruppe zurueck', count($bericht['restored']) === 1 && $bericht['refused'] === []);

    $danach = (int) $wpdb->get_var($wpdb->prepare("SELECT version FROM {$knoten} WHERE id = %d", (int) $probe->id));

    check('und auch hier zaehlt die Version weiter', $danach === $vorher + 2, (string) $danach);

    // ⚠️ *Und ohne Version im Journal wird ebenfalls verweigert — die 19 968 alten Zeilen haben keine.*
    $wpdb->query($wpdb->prepare("UPDATE {$journal} SET version = NULL WHERE change_group_id = %d", $gruppe));

    $bericht = Restore::group($gruppe);

    check(
        'ohne Version im Journal wird verweigert',
        str_contains($bericht['refused'][0]['why'] ?? '', 'keine Version'),
        $bericht['refused'][0]['why'] ?? 'keine Begründung'
    );

    $wpdb->query($wpdb->prepare("UPDATE {$knoten} SET version = %d WHERE id = %d", $vorher, (int) $probe->id));
}

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
