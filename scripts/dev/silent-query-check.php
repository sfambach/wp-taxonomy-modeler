<?php declare(strict_types=1);
/**
 * Eine kaputte Abfrage darf nicht als leeres Ergebnis durchgehen.
 *
 *     php scripts/dev/silent-query-check.php [path/to/wordpress]
 *
 * ⚠️ **Warum es diesen Wächter gibt, und was das Fehlen gekostet hat.** *Beim Umbau auf `sort_order`
 * (TASK-012) blieben zwei `ORDER BY r.position` stehen. Die Kinderabfrage antwortete daraufhin
 * **leer statt zu scheitern**; die Gerüste prüfen ihre eigene Arbeit, indem sie die vorhandenen
 * Kinder nach Namen durchsehen, fanden keine — und legten alles ein zweites Mal an: **38 Knoten und
 * 54 Kanten Rückstand in einem einzigen Durchlauf.** **Kein Wächter hat das gefunden**; gefunden hat
 * es eine Renderer-Auswahl, die die falsche Liste zeigte.*
 *
 * **Der Kern ist eine Klasse von Fehlern, nicht dieser eine Tippfehler: eine leere Antwort und eine
 * kaputte Abfrage sehen bei `$wpdb` gleich aus.** `get_results()` gibt für beides `null`, `$wpdb`
 * meldet einen Fehler nicht von selbst, und `?: []` macht daraus dieselbe leere Liste.
 *
 * Geprüft wird fünferlei:
 *
 * 1. **Eine kaputte Abfrage wirft** — Zeilen, Zeile, Wert und Spalte, alle vier Wege.
 * 2. **Eine leere Anweisung wirft** — die zweite stille Tür: `prepare()` gibt bei falscher Zahl von
 *    Platzhaltern `null` zurück, ohne zu werfen.
 * 3. **Ein wirklich leeres Ergebnis bleibt leer** — der Wächter darf nicht zum Gegenteil erziehen.
 * 4. **Die Lesewege der Speicher gehen über {@see Query}** — gemessen im Quelltext, damit die nächste
 *    hinzugefügte Abfrage nicht am Netz vorbei entsteht.
 * 5. **`relations.position` ist im Quelltext fort** — der Rest, der es ausgelöst hat.
 *
 * ⚠️ *Er legt nichts an und räumt nichts weg: er liest, und was er fragt, fragt er gegen die eigenen
 * Tabellen.*
 *
 * @see docs/pakete/modelltabellen/package.md
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

use Taxmod\WordPress\Persistence\Query;
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

/** Läuft der Aufruf in eine Ausnahme? Das ist hier das erwünschte Ergebnis. */
function wirft(callable $tun): bool
{
    try {
        $tun();
    } catch (\Throwable) {
        return true;
    }

    return false;
}

$nodes = Schema::table('nodes');

// ⚠️ *Die Fehler gehören zum Versuch und nicht auf den Schirm — sonst liest sich ein grüner Lauf wie
// ein kaputter.*
$wpdb->suppress_errors(true);
$wpdb->hide_errors();

echo "1 · Eine kaputte Abfrage wirft, statt leer zu antworten\n";

// Genau der Fehler von TASK-012: eine Spalte, die es nicht mehr gibt.
$kaputt = 'SELECT id FROM ' . $nodes . ' ORDER BY spalte_die_es_nicht_gibt';

check('Query::rows wirft', wirft(static fn () => Query::rows('Probe', $kaputt)));
check('Query::row wirft', wirft(static fn () => Query::row('Probe', $kaputt)));
check('Query::value wirft', wirft(static fn () => Query::value('Probe', $kaputt)));
check('Query::column wirft', wirft(static fn () => Query::column('Probe', $kaputt)));
check('Query::run wirft', wirft(static fn () => Query::run('Probe', 'UPDATE ' . $nodes . ' SET gibt_es_nicht = 1')));

// ⚠️ *Und der Gegenbeweis in derselben Sekunde: derselbe Weg über `$wpdb` allein antwortet still.*
$wpdb->last_error = '';
$still            = $wpdb->get_results($kaputt, ARRAY_A);
check(
    'ohne Query sähe dasselbe wie «nichts gefunden» aus',
    ($still ?: []) === [],
    'der Vergleich, der den Wächter begründet'
);

echo "\n2 · Eine leere Anweisung wirft\n";

check('null als Anweisung', wirft(static fn () => Query::rows('Probe', null)));
check('leere Zeichenkette', wirft(static fn () => Query::rows('Probe', '   ')));

// `prepare()` mit einer Vorlage ohne Platzhalter gibt nichts Brauchbares zurück — die zweite stille Tür.
check(
    'prepare() ohne Werte kommt nicht durch',
    wirft(static function () use ($wpdb, $nodes): void {
        /** @phpstan-ignore-next-line — der Fehlgebrauch ist genau der Fall, der geprüft wird. */
        Query::rows('Probe', @$wpdb->prepare('SELECT id FROM ' . $nodes . ' WHERE id = %d'));
    })
);

echo "\n3 · Ein wirklich leeres Ergebnis bleibt leer\n";

$leer = $wpdb->prepare('SELECT id FROM ' . $nodes . ' WHERE id = %d', -1);

check('rows gibt die leere Liste', Query::rows('Probe', $leer) === []);
check('row gibt null', Query::row('Probe', $leer) === null);
check('value gibt null', Query::value('Probe', $leer) === null);
check('column gibt die leere Liste', Query::column('Probe', $leer) === []);

// Und eine Abfrage, die es wirklich gibt, kommt auch wirklich an.
$anzahl = Query::value('Knoten zählen', 'SELECT COUNT(*) FROM ' . $nodes);
check('eine gültige Abfrage antwortet', $anzahl !== null && (int) $anzahl > 0, 'gezählt: ' . (string) $anzahl);

$wpdb->suppress_errors(false);
$wpdb->show_errors();

echo "\n4 · Die Lesewege der Speicher gehen über Query\n";

$quelle = dirname(__DIR__, 2) . '/src/WordPress/Persistence';

/** @var array<string, int> $offen */
$offen = [];

foreach (['WpdbNodeRepository', 'WpdbRelationRepository', 'WpdbRecordRepository', 'WpdbLabelRepository', 'WpdbChangelog'] as $klasse) {
    $text = (string) file_get_contents($quelle . '/' . $klasse . '.php');
    $zahl = preg_match_all('/\$wpdb->get_(results|row|var|col)\(/', $text);

    if ($zahl > 0) {
        $offen[$klasse] = $zahl;
    }
}

check(
    'kein direktes $wpdb->get_* in den Speichern',
    $offen === [],
    implode(', ', array_map(static fn (string $k, int $z): string => "$k: $z", array_keys($offen), $offen))
);

echo "\n5 · relations.position ist fort\n";

// ⚠️ *`relation_records.position` gibt es weiterhin und zu Recht — gesucht wird die Spalte auf `relations`.*
$reste = [];

foreach (['src', 'scripts', 'tests'] as $ordner) {
    $verzeichnis = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/' . $ordner)
    );

    foreach ($verzeichnis as $datei) {
        // ⚠️ *Diese Datei selbst nicht — sie trägt das Muster, nach dem sie sucht.*
        if (! $datei->isFile() || $datei->getExtension() !== 'php' || $datei->getRealPath() === realpath(__FILE__)) {
            continue;
        }

        // ⚠️ **Nur die Zeichenketten, nicht die Prosa.** *Ein Kommentar darf `relations.position`
        // erklären — er tut nichts. Gesucht wird, was an die Datenbank geht, und das steht in einer
        // Zeichenkette. Deshalb der Zerteiler und kein `grep` über die ganze Datei.*
        $sql = '';

        foreach (token_get_all((string) file_get_contents($datei->getPathname())) as $stueck) {
            if (is_array($stueck) && in_array($stueck[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $sql .= $stueck[1] . "\n";
            }
        }

        if (preg_match('/\b(r|e|rel|relations)\.position\b|\bMAX\(position\)|SET from_node_id = %d, position\b/', $sql) === 1) {
            $reste[] = $datei->getPathname();
        }
    }
}

check('keine Kantenabfrage nennt position', $reste === [], implode(', ', $reste));

$spalten = array_map(
    static fn (array $z): string => (string) $z['Field'],
    Query::rows('Spalten von relations', 'SHOW COLUMNS FROM ' . Schema::table('relations'))
);

check('die Tabelle hat keine Spalte position', ! in_array('position', $spalten, true));

echo "\n$ok OK, $bad FAIL\n";

exit($bad === 0 ? 0 : 1);
