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
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\WordPress\Persistence\Schema;

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

    $zuviel = array_diff(array_keys($s), array_keys($l), Schema::SHADOW_ONLY);

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
