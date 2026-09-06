<?php declare(strict_types=1);
/**
 * Lebende Tabelle und ihr Schatten haben dieselbe Form.
 *
 *     php scripts/dev/shadow-shape-check.php [path/to/wordpress]
 *
 * ⚠️ **Das ist der Wächter für den einen Preis, den [D-537](../../docs/NewConcept/90-decision-log.md)
 * bezahlt.** *Der Eigentümer hatte **eine** Tabelle mit einem Kennzeichen vorgeschlagen, und sein
 * Vorteil war echt: eine Form, ein Schemaschritt. Zwei Tabellen derselben Form laufen auseinander,
 * sobald eine Spalte hinzukommt — und am 2026-08-30 kamen zwei, `multiplicity` und `position`.*
 *
 * ⚠️ **Entschieden hat eine Unsymmetrie, und diese Datei ist ihre eine Hälfte:** *«eine Spalte nur in
 * der lebenden angelegt» findet **diese Prüfung, bei jedem Lauf**. «Ein `WHERE is_live = 1` von 50
 * vergessen» hätte niemand gefunden — die Abfrage liefert mehr Zeilen und sieht richtig aus.*
 *
 * ⚠️ *Sie vergleicht **Namen und Datentypen**, nicht `EXTRA`: `records.id` ist lebend
 * `AUTO_INCREMENT` und im Schatten absichtlich nicht, weil die Nummer von der Zeile kommt, die
 * hinüberwandert.*
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

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\NodeRecord;
use Taxmod\Core\Model\TypedValue;
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

/** @return array<string,string> Spaltenname => Datentyp. */
function spalten(string $tabelle): array
{
    global $wpdb;

    $gefunden = [];

    foreach ($wpdb->get_results($wpdb->prepare(
        'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
        $tabelle
    ), ARRAY_A) ?: [] as $z) {
        $gefunden[(string) $z['COLUMN_NAME']] = (string) $z['COLUMN_TYPE'];
    }

    return $gefunden;
}

/** @return list<string> Die Spalten des Primärschlüssels, in ihrer Reihenfolge. */
function schluessel(string $tabelle): array
{
    global $wpdb;

    return array_map(strval(...), $wpdb->get_col($wpdb->prepare(
        "SELECT COLUMN_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'PRIMARY'
         ORDER BY SEQ_IN_INDEX",
        $tabelle
    )) ?: []);
}

echo "\n== Die Listen passen zueinander ==\n";

check(
    'gleich viele lebende Tabellen wie Schatten',
    count(Schema::LIVE_TABLES) === count(Schema::SHADOW_TABLES),
    count(Schema::LIVE_TABLES) . ' gegen ' . count(Schema::SHADOW_TABLES)
);

foreach (Schema::LIVE_TABLES as $i => $lebend) {
    $schatten = Schema::SHADOW_TABLES[$i] ?? null;

    if ($schatten === null) {
        check("{$lebend} hat einen Schatten", false, 'die Liste ist kürzer');

        continue;
    }

    echo "\n== {$lebend} gegen {$schatten} ==\n";

    $l = spalten(Schema::table($lebend));
    $s = spalten(Schema::table($schatten));

    // ⚠️ *Beide leer hiesse «Tabelle gibt es nicht» — das wäre grün aus dem falschen Grund.*
    if ($l === [] || $s === []) {
        check("beide Tabellen stehen", false, ($l === [] ? "{$lebend} fehlt " : '') . ($s === [] ? "{$schatten} fehlt" : ''));

        continue;
    }

    check('beide Tabellen stehen', true);

    $fehlend = array_diff(array_keys($l), array_keys($s));

    check(
        'jede lebende Spalte steht auch im Schatten',
        $fehlend === [],
        'fehlt im Schatten: ' . implode(', ', $fehlend)
    );

    // ⚠️ *Und die benannten Ausnahmen dieser einen Tabelle* ([D-619](../../docs/NewConcept/90-decision-log.md),
    // TASK-013): **`relations_history.parked_by_group_id` hat lebend absichtlich keine Entsprechung**
    // — eine geparkte Kante hat keine lebende Zeile. Benannt in {@see Schema::SHADOW_ONLY_IN}, damit
    // die Ausnahme im Quelltext steht und nicht in der Nachsicht dieser Prüfung.
    $zuviel = array_diff(
        array_keys($s),
        array_keys($l),
        Schema::SHADOW_ONLY,
        Schema::SHADOW_ONLY_IN[$schatten] ?? []
    );

    check(
        'der Schatten hat keine Spalte, die es lebend nicht gibt',
        $zuviel === [],
        'nur im Schatten: ' . implode(', ', $zuviel)
    );

    $andersTypisiert = [];

    foreach ($l as $name => $typ) {
        if (isset($s[$name]) && $s[$name] !== $typ) {
            $andersTypisiert[] = "{$name}: {$typ} gegen {$s[$name]}";
        }
    }

    check(
        'gleiche Spalten haben gleiche Datentypen',
        $andersTypisiert === [],
        implode(' · ', $andersTypisiert)
    );

    foreach (Schema::SHADOW_ONLY as $nur) {
        check("der Schatten hat «{$nur}»", isset($s[$nur]));
    }

    // ⚠️ **Der Kern von [D-537](../../docs/NewConcept/90-decision-log.md):** *lebend genau eine Zeile
    // je Identität, im Schatten eine je Version. **Der Primärschlüssel setzt beides durch**, und dass
    // er es tut, ist der Grund, warum es zwei Tabellen sind statt einer mit Kennzeichen.*
    check(
        "{$lebend} hat den Schlüssel «id» allein",
        schluessel(Schema::table($lebend)) === ['id'],
        implode(',', schluessel(Schema::table($lebend)))
    );

    check(
        "{$schatten} hat den Schlüssel «id, version»",
        schluessel(Schema::table($schatten)) === ['id', 'version'],
        implode(',', schluessel(Schema::table($schatten)))
    );
}

echo "\n== Und er schreibt wirklich ==\n";

// ⚠️ **Die Zusage, die gefehlt hat, und sie hat sofort etwas gefunden.** *Die Form stimmte, die
// Schlüssel stimmten, **und der Schatten blieb leer**: `Shadow::keep()` baute
// `VALUES(spalte)` statt `spalte = VALUES(spalte)`, MySQL wies die Abfrage ab, `$wpdb->query()` gab
// `false`, und das wurde als «null Zeilen» gelesen. **Eine Prüfung, die nur die Form vergleicht, ist
// mit einem leeren Schatten zufrieden.**
$vorlage = $wpdb->get_row(
    'SELECT node_record_id, relation_id, locale FROM ' . Schema::table('relation_records') . ' LIMIT 1'
);

if ($vorlage === null) {
    check('eine Wertzeile als Vorlage gefunden', false, 'relation_records ist leer');
} else {
    check('eine Wertzeile als Vorlage gefunden', true);

    $knotenId = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT node_id FROM ' . Schema::table('node_records') . ' WHERE id = %d',
        (int) $vorlage->node_record_id
    ));

    $records = new WpdbRecordRepository();
    $satzId  = $records->add(new NodeRecord(0, $knotenId, 1, '2026-08-30 00:00:00'));

    // ⚠️ *Räumt auch bei einem Absturz auf — samt der Geschichte, die dieser Lauf erzeugt hat.*
    register_shutdown_function(static function () use ($satzId): void {
        global $wpdb;

        foreach (['relation_records', 'records'] as $t) {
            $spalte = $t === 'node_records' ? 'id' : 'node_record_id';
            $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table($t) . " WHERE {$spalte} = %d", $satzId));
            $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . Schema::table($t . '_history') . " WHERE {$spalte} = %d",
                $satzId
            ));
        }
    });

    $records->putValue(RelationRecord::direct($satzId, (int) $vorlage->relation_id, TypedValue::ofText('erster Stand')));

    $geschrieben = $records->valuesOf($satzId);

    check('der erste Wert steht lebend', count($geschrieben) === 1, 'es sind ' . count($geschrieben));

    $imSchatten = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . Schema::table('relation_records_history') . ' WHERE node_record_id = %d',
        $satzId
    ));

    // ⚠️ *Ein **Anlegen** hebt nichts auf — es gibt keinen Vorgänger. Stünde hier eine Zeile, hielte
    // der Schatten den neuen Wert und nicht den alten.*
    check('ein Anlegen hebt nichts auf', $imSchatten === 0, "{$imSchatten} Zeilen");

    $records->putValue(new RelationRecord(
        $satzId,
        $geschrieben[0]->path,
        (int) $vorlage->relation_id,
        '',
        TypedValue::ofText('zweiter Stand'),
        $geschrieben[0]->id,
        $geschrieben[0]->position
    ));

    $jetzt = $records->valuesOf($satzId);

    check('lebend steht der neue Wert', ($jetzt[0]->value->text ?? null) === 'zweiter Stand');
    check('und die Version ist hochgezählt', count($jetzt) === 1, 'es sind ' . count($jetzt));

    $alt = $wpdb->get_row($wpdb->prepare(
        'SELECT value_text, version, deleted FROM ' . Schema::table('relation_records_history') . '
         WHERE node_record_id = %d ORDER BY version ASC',
        $satzId
    ));

    check('der Schatten hält den alten Wert', ($alt->value_text ?? null) === 'erster Stand', $alt->value_text ?? 'nichts');
    check('und er ist nicht als gelöscht vermerkt', ((int) ($alt->deleted ?? 1)) === 0);

    $records->forgetValueById((int) $jetzt[0]->id);

    check(
        'nach dem Entfernen ist lebend nichts mehr',
        $records->valuesOf($satzId) === []
    );

    $geloescht = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . Schema::table('relation_records_history') . '
         WHERE node_record_id = %d AND deleted = 1',
        $satzId
    ));

    check('und der Schatten hat eine gelöschte Version', $geloescht === 1, "{$geloescht} gefunden");
}

echo "\n== Kein Fremdschlüssel hängt an der Geschichte ==\n";

// ⚠️ *Eine alte Zeile führt ihre Verweise als **Datum** mit. Ein Fremdschlüssel hielte eine Identität
// am Leben, die längst weggeräumt wurde, und `ON DELETE RESTRICT` machte das Aufräumen unmöglich.*
foreach (Schema::SHADOW_TABLES as $schatten) {
    $anzahl = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND REFERENCED_TABLE_NAME IS NOT NULL',
        Schema::table($schatten)
    ));

    check("{$schatten} ohne Fremdschlüssel", $anzahl === 0, "{$anzahl} gefunden");
}

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
