<?php declare(strict_types=1);
/**
 * Mehrere Werte eines Feldes, und die Multiplizität an der Kante.
 *
 *     php scripts/dev/several-values-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-530](../../docs/NewConcept/90-decision-log.md) hat [D-527](../../docs/NewConcept/90-decision-log.md)
 * am selben Tag abgelöst, und der Eigentümer hat den Fehler in einem Satz gesehen:** *«warum führen
 * wir jetzt eine neue Zahl ein, wo wir doch die Id des Records haben?»* — die laufende Nummer im Pfad
 * war eine erfundene vierte Angabe neben drei vorhandenen.
 *
 * ⚠️ **Diese Prüfung gehört an den Rand und nicht in den Kern**, *weil der eindeutige Schlüssel in
 * der **Datenbank** stand. Ein Kerntest hätte «drei Zeilen nebeneinander» auch grün gemeldet, während
 * MySQL zwei davon verschluckte.*
 *
 * ⚠️ *Und [D-528](../../docs/NewConcept/90-decision-log.md): die Multiplizität liegt als Spalte an der
 * Kante und wird beim Ableiten mitgetragen — **`renamedTo()` und `revived()` liessen `hide` vorher
 * fallen**, gemessen am 2026-08-30, und ein viertes getragenes Feld hätte denselben Fehler geerbt.*
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

use Taxmod\Core\Exception\NotYetStorable;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

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

$nodes     = new WpdbNodeRepository();
$relations     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$records   = new WpdbRecordRepository();
$data      = new DataEntry($records, $relations, $nodes, $framework, new SystemClock());

/** @var list<int> Alles, was dieser Lauf angelegt hat. */
$meine = [];

register_shutdown_function(static function () use (&$meine): void {
    global $wpdb;

    foreach ($meine as $id) {
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('node_records') . ' WHERE id = %d', $id));
    }
});

echo "\n== 1. Der eindeutige Schluessel ist weg ==\n";

// ⚠️ *Er war der ganze Grund für D-527s laufende Nummer. Steht er noch, verschluckt MySQL den
// zweiten Wert — und alles darunter wäre grün aus dem falschen Grund.*
$eindeutige = $wpdb->get_col(
    "SELECT INDEX_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . Schema::table('relation_records') . "'
       AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY'"
);

check('kein eindeutiger Schluessel mehr neben PRIMARY', $eindeutige === [], implode(', ', $eindeutige));

check(
    'position ist eine Spalte',
    $wpdb->get_var('SHOW COLUMNS FROM ' . Schema::table('relation_records') . " LIKE 'position'") !== null
);

check(
    'und of_field indiziert die Frage, die es jetzt gibt',
    in_array('of_field', $wpdb->get_col(
        "SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '" . Schema::table('relation_records') . "'"
    ), true)
);

echo "\n== 2. Ein Feld auf einen einfachen Typ, an echten Daten ==\n";

// ⚠️ *Gesucht statt benannt: eine Kante, deren Ziel unter «Data Types» liegt und deren Besitzer
// Datensätze halten darf. **Findet sie sich nicht, meldet die Prüfung das** statt durchzulaufen —
// eine Prüfung, die ihren Gegenstand nicht findet, ist nicht grün.*
$kandidat = null;

foreach ($wpdb->get_results(
    'SELECT id, from_node_id, to_node_id, name FROM ' . Schema::table('relations') . "
     WHERE kind <> 'inheritance'"
) as $zeile) {
    $ziel     = $nodes->find((int) $zeile->to_node_id);
    $besitzer = $nodes->find((int) $zeile->from_node_id);

    if ($ziel === null || $besitzer === null) {
        continue;
    }

    if ($framework->branchOf($ziel) !== Branch::DataTypes) {
        continue;
    }

    $besitzerAst = $framework->branchOf($besitzer);

    if ($besitzerAst === null || ! $besitzerAst->holdsData()) {
        continue;
    }

    $kandidat = $zeile;

    break;
}

if ($kandidat === null) {
    check('eine Kante auf einen einfachen Typ gefunden', false, 'keine passende Kante im Modell');

    echo "\n$bad fehlgeschlagen, $ok in Ordnung\n";

    exit(1);
}

check('eine Kante auf einen einfachen Typ gefunden', true, (string) $kandidat->name);

$satz    = $data->create((int) $kandidat->from_node_id);
$meine[] = $satz->id;

$data->appendValue($satz->id, (int) $kandidat->id, TypedValue::ofText('erster'));
$data->appendValue($satz->id, (int) $kandidat->id, TypedValue::ofText('zweiter'));
$data->appendValue($satz->id, (int) $kandidat->id, TypedValue::ofText('dritter'));

$werte = $records->valuesOf($satz->id);

check('drei angehaengte Werte stehen nebeneinander', count($werte) === 3, 'es sind ' . count($werte));

check(
    'alle drei teilen sich einen Pfad',
    count(array_unique(array_map(static fn ($w): string => $w->path, $werte))) === 1
);

check(
    'und drei verschiedene Zeilen-Ids trennen sie',
    count(array_unique(array_map(static fn ($w): ?int => $w->id, $werte))) === 3
);

check(
    'position ordnet sie aufsteigend',
    array_map(static fn ($w): int => $w->position, $werte) === [0, 1, 2],
    implode(',', array_map(static fn ($w): int => $w->position, $werte))
);

echo "\n== 3. Den mittleren entfernen ==\n";

$records->forgetValueById((int) $werte[1]->id);
$uebrig = $records->valuesOf($satz->id);

check('zwei bleiben', count($uebrig) === 2, 'es sind ' . count($uebrig));

check(
    'und es sind der erste und der dritte',
    array_map(static fn ($w): ?string => $w->value->text, $uebrig) === ['erster', 'dritter']
);

// ⚠️ *Die Stellen rücken **nicht** nach: an der Zeilen-Id hängt der gezeichnete Entfernen-Knopf, und
// ein Nachrücken machte jeden davon falsch.*
check(
    'die Stellen ruecken nicht nach',
    array_map(static fn ($w): int => $w->position, $uebrig) === [0, 2]
);

echo "\n== 4. «Setze den Wert» verweigert bei mehreren ==\n";

$verweigert = false;

try {
    $data->put($satz->id, (int) $kandidat->id, TypedValue::ofText('welcher denn?'));
} catch (NotYetStorable) {
    $verweigert = true;
}

check('put() verweigert, wenn ein Feld mehrere Werte haelt', $verweigert);

echo "\n== 5. Und einer allein laesst sich weiter setzen ==\n";

$records->forgetValueById((int) $uebrig[1]->id);
$data->put($satz->id, (int) $kandidat->id, TypedValue::ofText('geaendert'));
$einer = $records->valuesOf($satz->id);

check('es bleibt eine Zeile', count($einer) === 1, 'es sind ' . count($einer));
check('mit dem neuen Wert', ($einer[0]->value->text ?? null) === 'geaendert');

// ⚠️ *Die Id muss dieselbe bleiben: `replace()` löschte und schrieb neu, also **bekam jede Zeile bei
// jedem Speichern eine neue Id** — und an der Id hängt jetzt, welcher Wert das ist.*
check('und dieselbe Zeile wie vorher', $einer[0]->id === $uebrig[0]->id, 'put() hat eine neue Zeile geschrieben');

echo "\n== 6. Die Multiplizitaet liegt an der Kante ==\n";

// ⚠️ *Es gibt kein `find()` für eine einzelne Kante — die Felder des Besitzers reichen, und der
// Umweg zeigt zugleich, dass die Spalte auch auf dem gewöhnlichen Leseweg ankommt.*
$kante = null;

foreach ($relations->fieldRelationsOf([(int) $kandidat->from_node_id]) as $eine) {
    if ($eine->id === (int) $kandidat->id) {
        $kante = $eine;
    }
}

if ($kante === null) {
    check('die Kante ueber den gewoehnlichen Leseweg gefunden', false, 'fieldRelationsOf kennt sie nicht');

    echo "\n$bad fehlgeschlagen, $ok in Ordnung\n";

    exit(1);
}

check('die Kante ueber den gewoehnlichen Leseweg gefunden', true);
check('eine Kante hat eine Multiplizitaet', $kante->multiplicity instanceof Multiplicity);

check(
    'und die Spalte steht in relations',
    $wpdb->get_var('SHOW COLUMNS FROM ' . Schema::table('relations') . " LIKE 'multiplicity'") !== null
);

// ⚠️ *Der Fehler, den D-528 nebenbei gefunden hat. **Ohne zu speichern** geprüft — die Kante aus dem
// Modell reicht, und nichts wird angefasst.*
$umbenannt = $kante->renamedTo($kante->name . ' x');

check('umbenennen traegt die Multiplizitaet mit', $umbenannt->multiplicity === $kante->multiplicity);
check('umbenennen traegt hide mit', $umbenannt->hide === $kante->hide);
check('wiederherstellen traegt hide mit', $kante->parkedBy(1)->revived()->hide === $kante->hide);

echo "\n== 7. Jedes Stueck eines Pfades ist eine Kanten-Id ==\n";

// ⚠️ **Diese Zusage hiess bis zum Umzug «kein Pfad hat einen Punkt», und das war zu grob.** *Damals
// bedeutete ein Punkt eine laufende Nummer ([D-527](../../docs/NewConcept/90-decision-log.md)); **seit
// die Renderer an ihren Verwendungsstellen liegen, ist ein Punkt die richtige Form** — «Feld A, darin
// Feld B». Der Umzug hat die alte Zusage rot gemacht, und das war sie auch: sie verbot die Form,
// statt die Bedeutung zu prüfen.*
//
// ⚠️ **Was wirklich gilt** ([D-530](../../docs/NewConcept/90-decision-log.md)): *jedes Stück zwischen
// zwei Punkten ist eine **Kanten-Id**. `ModelEditor::remapPath()` verlässt sich darauf — es schickt
// jedes Segment durch die Kantenabbildung, und eine laufende Nummer dort wäre eine Kante, die niemand
// gemeint hat.*
foreach (['relation_records', 'settings', 'labels'] as $tabelle) {
    $pfade = $wpdb->get_col(
        'SELECT DISTINCT path FROM ' . Schema::table($tabelle) . " WHERE path <> ''"
    ) ?: [];

    $fremd = [];

    foreach ($pfade as $pfad) {
        foreach (explode('.', (string) $pfad) as $stueck) {
            $ist = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Schema::table('relations') . ' WHERE id = %d',
                (int) $stueck
            ));

            if ($ist === 0) {
                $fremd[] = "{$pfad} (Stück «{$stueck}»)";
            }
        }
    }

    check(
        "jedes Stueck in {$tabelle} ist eine Kante",
        $fremd === [],
        implode(' · ', array_slice($fremd, 0, 4))
    );
}

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
