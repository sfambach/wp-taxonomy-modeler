<?php declare(strict_types=1);
/**
 * Drei Werte, drei Klassen — und **kein Datensatz haengt an zwei Besitzern**.
 *
 *     php scripts/dev/edge-class-check.php [path/to/wordpress]
 *
 * ⚠️ *Der Dateiname trägt als einziger im Baum noch das Wort «edge» (TASK-016,
 * [D-576](../../docs/NewConcept/90-decision-log.md)). **Er bleibt, weil an dieser Datei gerade
 * jemand anders baut** — umbenannt wird sie, wenn TASK-032 eingecheckt ist.*
 *
 * ⚠️ **[D-639](../../docs/NewConcept/90-decision-log.md), sein Wort:** *«dann frage ich mich, ob wir
 * diesen Schalter nicht einfach weglassen und das ueber einen dritten Wert Komposition machen. Dann
 * haben wir **ein Mittel**, das bestimmt, was fuer eine Verbindung es ist, und nicht noch einen
 * Schalter.»* Und die Berichtigung, die er mir danach gab: *«Komposition sagt ja nicht, dass wenn ich
 * den Knoten loesche im Modell, auch der Kompositionsknoten mitgeloescht wird. Er sagt nur: wenn ich
 * einen **Datensatz** loesche — also den Datensatz von Kunde A —, dann muss auch die Adresse von
 * Kunde A geloescht werden.»*
 *
 * ⚠️ **Der Waechter, den es hier ausdruecklich *nicht* gibt, und warum das im Kopf steht:** *ich
 * hatte «eine Komposition darf nicht auf ein geteiltes Ziel zeigen» vorgeschlagen. **Gemessen zeigen
 * 36 von 42 Kompositionskanten auf Typknoten** — `Text` 26 mal, `Einheitenwert` 6 mal —, und das ist
 * richtig: ein Typ ist im Modell geteilt. **Die Pruefung haette 36 richtige Zeilen berichtigt.** Sie
 * vermischte Modell und Daten; exklusiv ist der **Datensatz**, nicht der Knoten.*
 *
 * ```mermaid
 * flowchart LR
 *   S["relations.kind · drei Werte"] -->|classFor, eine Stelle| K["SettingEdge · AggregationEdge · CompositionEdge"]
 *   R[("ein Datensatz")] --> B["genau ein Besitzer"]
 * ```
 *
 * Geprueft wird viererlei:
 *
 * 1. **Jede lebende Kante traegt einen der drei Werte** — keinen vierten, keinen leeren.
 * 2. **Die Ableitung Wert → Klasse ist vollstaendig und eindeutig**: jeder Aufzaehlungsfall bekommt
 *    seine eigene Klasse, und keine steht fuer zwei.
 * 3. **Eine geladene Kante kommt als ihre Klasse an** — ueber den echten Speicher, nicht gebastelt.
 * 4. **Kein Datensatz haengt an zwei Besitzern** — und der Waechter legt sich den Verstoss selbst an,
 *    damit die Zusage nicht nur gruen ist, weil der Bestand zufaellig sauber aussieht.
 *
 * ⚠️ **Die Tabellennamen stehen hier als {@see Schema::LIVE_TABLES} und nicht ausgeschrieben.**
 * *Waehrend dieser Waechter entstand, lief im selben Baum die Umbenennung der Satztabellen. **Ein
 * Waechter, der Namen ausschreibt, wird von einer Umbenennung rot** — und dann bewacht er den
 * Namen statt der Zusage. Die Reihenfolge der Liste ist der Vertrag: Knoten, Kanten, Saetze, Werte.*
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

use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\SeededRole;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Persistence\Query;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;

// ⚠️ *Seit TASK-019 traegt jeder Knoten eine Beschriftungszeile ([D-580](../../docs/NewConcept/90-decision-log.md)) —
// und dieser Lauf raeumt Knoten mit rohem SQL weg, also am Ende hinter sich her. **Es faellt nur,
// worauf weder ein Knoten noch eine Kante zeigt.***
register_shutdown_function(static fn (): int => \Taxmod\WordPress\Persistence\Schema::forgetOrphanLabels());

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

/**
 * Die eine Abfrage, die «zwei Besitzer» ausdrueckt.
 *
 * ⚠️ **Ein Datensatz wurde an vier Stellen gehalten; seit TASK-057 ist es *eine*.** *Drei davon
 * waren die Spalten aus TASK-020 — `nodes.settings_record_id` und die beiden an der Kante —, und
 * sie sind gefallen ([D-642](../../docs/NewConcept/90-decision-log.md),
 * [D-643](../../docs/NewConcept/90-decision-log.md)). **Uebrig bleibt die Tuer, die es immer schon
 * gab:** ein `value_ref`, das auf einen Datensatz statt auf einen Knoten zeigt — der
 * zusammengesetzte Teil ([D-232](../../docs/NewConcept/90-decision-log.md)). *Der Renderer geht
 * jetzt durch dieselbe.*
 *
 * ⚠️ **Die Frage ist nicht kleiner geworden, ihre Antwortmenge ist es.** *Vier Quellen zu fragen,
 * von denen drei nicht mehr existieren koennen, waere eine Pruefung auf einen Zustand von gestern
 * (`PR-9`). **Dass die Spalten weg bleiben, bewacht `settings-record-carrier-check.php`.***
 *
 * @return list<array<string, string|null>>
 */
function zweiBesitzer(): array
{
    $values = Schema::table(Schema::LIVE_TABLES[3]);

    return Query::rows(
        'Datensaetze mit mehr als einem Besitzer suchen',
        "SELECT rid, COUNT(*) anzahl, GROUP_CONCAT(quelle) besitzer FROM (
             SELECT value_ref rid, CONCAT('wertzeile:', id) quelle
                 FROM {$values} WHERE value_ref_kind = 'record'
         ) halter GROUP BY rid HAVING anzahl > 1"
    );
}

echo "\n1 · die Spalte traegt drei Werte und keinen vierten\n";

$werte = Query::rows(
    'die Kantenarten im Bestand zaehlen',
    'SELECT kind, COUNT(*) anzahl FROM ' . Schema::table(Schema::LIVE_TABLES[1]) . ' GROUP BY kind'
);

$erlaubt = array_map(static fn (RelationKind $a): string => $a->value, RelationKind::cases());
$fremd   = array_values(array_filter(
    $werte,
    static fn (array $z): bool => ! in_array((string) $z['kind'], $erlaubt, true)
));

check(
    'jede lebende Kante traegt einen der drei Werte',
    $fremd === [],
    implode(', ', array_map(static fn (array $z): string => (string) $z['kind'], $fremd))
);

check(
    'und es sind genau drei erlaubte',
    count($erlaubt) === 3,
    implode('/', $erlaubt)
);

echo '       im Bestand: ' . implode(', ', array_map(
    static fn (array $z): string => $z['kind'] . ' ' . $z['anzahl'],
    $werte
)) . "\n";

echo "\n2 · die Ableitung Wert → Klasse ist vollstaendig und eindeutig\n";

$klassen = [];

foreach (RelationKind::cases() as $art) {
    $klasse = Relation::classFor($art);
    check(
        "«{$art->value}» hat eine eigene Klasse",
        class_exists($klasse) && ! isset($klassen[$klasse]),
        $klasse
    );
    $klassen[$klasse] = $art->value;
}

check(
    'und keine Klasse steht fuer zwei Werte',
    count($klassen) === count(RelationKind::cases()),
    count($klassen) . ' Klassen für ' . count(RelationKind::cases()) . ' Werte'
);

echo "\n3 · eine geladene Kante kommt als ihre Klasse an\n";

$kanten   = new WpdbRelationRepository();
$geladen  = [];
$falsche  = [];

foreach (Query::column('die Besitzer aller Kanten holen', 'SELECT DISTINCT from_node_id FROM ' . Schema::table(Schema::LIVE_TABLES[1])) as $besitzer) {
    foreach ($kanten->fieldRelationsOf([(int) $besitzer]) as $kante) {
        $geladen[$kante->kind->value] = ($geladen[$kante->kind->value] ?? 0) + 1;

        if ($kante::class !== Relation::classFor($kante->kind)) {
            $falsche[] = $kante->id . ': ' . $kante::class;
        }
    }
}

check(
    'jede geladene Kante ist ein Exemplar ihrer Klasse',
    $falsche === [],
    implode(', ', array_slice($falsche, 0, 5))
);

check(
    'und es wurde wirklich etwas geladen',
    array_sum($geladen) > 0,
    (string) array_sum($geladen)
);

echo '       geladen: ' . implode(', ', array_map(
    static fn (string $a, int $n): string => "$a $n",
    array_keys($geladen),
    $geladen
)) . "\n";

echo "\n4 · kein Datensatz haengt an zwei Besitzern\n";

// ⚠️ *Fehlt eine der vier Tabellen, ist die Zusage nicht erfuellt, sondern **nicht pruefbar** — und
// das ist ein Unterschied, den ein stiller Abbruch verwischt. Rot mit Grund statt Stapelabzug.*
foreach ([Schema::LIVE_TABLES[0], Schema::LIVE_TABLES[1], Schema::LIVE_TABLES[2], Schema::LIVE_TABLES[3]] as $noetig) {
    $name = Schema::table($noetig);

    if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) !== $name) {
        check('alle vier Haltertabellen stehen', false, "«{$name}» fehlt — der Bestand ist nicht pruefbar");
        echo "\n" . "$bad fehlgeschlagen, $ok in Ordnung\n";

        exit(1);
    }
}

$vorher = zweiBesitzer();

check(
    'im Bestand haelt kein Datensatz zwei Besitzer',
    $vorher === [],
    implode('; ', array_map(
        static fn (array $z): string => 'Satz ' . $z['rid'] . ' an ' . $z['besitzer'],
        array_slice($vorher, 0, 5)
    ))
);

echo '       geprueft gegen ' . Query::value('Datensaetze zaehlen', 'SELECT COUNT(*) FROM ' . Schema::table(Schema::LIVE_TABLES[2]))
    . " Datensaetze\n";

// ⚠️ *Und jetzt der Gegenbeweis: der Waechter legt den Verstoss selbst an, sieht ihn, und raeumt ihn
// weg. **Ohne ihn waere Punkt 4 auch dann gruen, wenn die Abfrage gar nichts findet.*** Alles unter
// dem Vorsatz `__`, und es faellt auch bei einem Abbruch (siehe unten).
$meine = ['knoten' => [], 'satz' => null, 'halter' => null];

register_shutdown_function(static function () use (&$meine): void {
    // ⚠️ *Der Notausgang raeumt auch die Probezeilen — sie sind seit TASK-057 der Weg, auf dem der
    // Gegenfall gebaut wird, und ein Abbruch mittendrin liesse sie sonst stehen.*
    if ($meine['halter'] !== null) {
        Query::run('Probezeilen wegraeumen', $GLOBALS['wpdb']->prepare(
            'DELETE FROM ' . Schema::table(Schema::LIVE_TABLES[3]) . ' WHERE node_record_id = %d',
            $meine['halter']
        ));

        Query::run('Halter wegraeumen', $GLOBALS['wpdb']->prepare(
            'DELETE FROM ' . Schema::table(Schema::LIVE_TABLES[2]) . ' WHERE id = %d',
            $meine['halter']
        ));
    }

    if ($meine['satz'] !== null) {
        Query::run('Probesatz wegraeumen', $GLOBALS['wpdb']->prepare(
            'DELETE FROM ' . Schema::table(Schema::LIVE_TABLES[2]) . ' WHERE id = %d',
            $meine['satz']
        ));
    }

    foreach ($meine['knoten'] as $id) {
        Query::run('Probeknoten wegraeumen', $GLOBALS['wpdb']->prepare(
            'DELETE FROM ' . Schema::table(Schema::LIVE_TABLES[0]) . ' WHERE id = %d',
            $id
        ));
    }
});

$wurzel = (int) Query::value('die Wurzel finden', 'SELECT id FROM ' . Schema::table(Schema::LIVE_TABLES[0]) . ' WHERE parent_node_id IS NULL LIMIT 1');

// ⚠️ *Eine freie Stelle je Probeknoten, nicht zweimal die null: `one_place` steht seit TASK-012 als
// eindeutiger Schluessel ueber `(parent_node_id, sort_order)`, und zwei Knoten auf derselben Stelle
// wies MySQL zurueck — **still**, denn `$wpdb` sagt darueber nichts. Gemessen, nicht vermutet.*
$stelle = 1 + (int) Query::value(
    'die letzte Stelle unter der Wurzel holen',
    $wpdb->prepare(
        'SELECT COALESCE(MAX(sort_order), 0) FROM ' . Schema::table(Schema::LIVE_TABLES[0]) . ' WHERE parent_node_id = %d',
        $wurzel
    )
);

// ⚠️ *Seit TASK-019 hat `nodes` keine Spalte `name` mehr ([D-580](../../docs/NewConcept/90-decision-log.md));
// der Name ist eine Beschriftung und wird danach geschrieben.*
$beschriftungen = new WpdbLabelRepository();

foreach (['__zweiBesitzerA', '__zweiBesitzerB'] as $name) {
    // ⚠️ *Seit Fassung 35 hat `nodes` keine Spalte `path` mehr (TASK-001) — die Einordnung steht in
    // `parent_node_id`, und der Weg wird beim Lesen daraus gerechnet.*
    Query::run('Probeknoten anlegen', $wpdb->prepare(
        'INSERT INTO ' . Schema::table(Schema::LIVE_TABLES[0]) . ' (version, parent_node_id, sort_order, hide)
         VALUES (1, %d, %d, 1)',
        $wurzel,
        $stelle++
    ));

    $neu = (int) $wpdb->insert_id;

    $beschriftungen->put(new Label(
        $neu,
        IdentitySpace::Node,
        SeededRole::Name,
        Label::BASE_NUMBER,
        SettingsScreen::neutralLocale(),
        $name
    ));

    $meine['knoten'][] = $neu;
}

check('beide Probeknoten sind entstanden', count($meine['knoten']) === 2 && ! in_array(0, $meine['knoten'], true));

Query::run('Probesatz anlegen', $wpdb->prepare(
    'INSERT INTO ' . Schema::table(Schema::LIVE_TABLES[2]) . ' (node_id, node_version, created_at, record_type)
     VALUES (%d, 1, %s, %s)',
    $meine['knoten'][0],
    gmdate('Y-m-d H:i:s'),
    'default'
));
$meine['satz'] = (int) $wpdb->insert_id;

// ⚠️ **Der Verstoss wird jetzt an Wertzeilen gebaut und nicht mehr an zwei Spalten** (TASK-057).
// *Die Spalten gibt es nicht mehr; die Tuer, die bleibt, ist der Satzverweis
// ([D-232](../../docs/NewConcept/90-decision-log.md)). **Der Gegenfall muss durch dieselbe Tuer wie
// der echte Fall** — sonst prueft er einen Weg, den niemand mehr gehen kann.*
Query::run('Halter fuer die Probezeilen anlegen', $wpdb->prepare(
    'INSERT INTO ' . Schema::table(Schema::LIVE_TABLES[2]) . ' (node_id, node_version, created_at, record_type)
     VALUES (%d, 1, %s, %s)',
    $meine['knoten'][1],
    gmdate('Y-m-d H:i:s'),
    'default'
));
$meine['halter'] = (int) $wpdb->insert_id;

foreach (['__a', '__b'] as $pfad) {
    Query::run('zwei Wertzeilen auf denselben Satz zeigen lassen', $wpdb->prepare(
        'INSERT INTO ' . Schema::table(Schema::LIVE_TABLES[3])
            . " (node_record_id, relation_id, path, locale, value_ref, value_ref_kind, position, version)
               VALUES (%d, 0, %s, '', %d, 'record', 0, 1)",
        $meine['halter'],
        $pfad,
        $meine['satz']
    ));
}

$gesehen = array_values(array_filter(
    zweiBesitzer(),
    static fn (array $z): bool => (int) $z['rid'] === $meine['satz']
));

check(
    'und der Waechter sieht den Fall, wenn es ihn gibt',
    count($gesehen) === 1 && (int) $gesehen[0]['anzahl'] === 2,
    $gesehen === [] ? 'der angelegte Verstoss blieb unbemerkt' : (string) $gesehen[0]['anzahl']
);

Query::run('Probezeilen wegraeumen', $wpdb->prepare(
    'DELETE FROM ' . Schema::table(Schema::LIVE_TABLES[3]) . ' WHERE node_record_id = %d',
    $meine['halter']
));

Query::run('Halter wegraeumen', $wpdb->prepare('DELETE FROM ' . Schema::table(Schema::LIVE_TABLES[2]) . ' WHERE id = %d', $meine['halter']));

foreach ($meine['knoten'] as $id) {
    Query::run('Probeknoten wegraeumen', $wpdb->prepare('DELETE FROM ' . Schema::table(Schema::LIVE_TABLES[0]) . ' WHERE id = %d', $id));
}

Query::run('Probesatz wegraeumen', $wpdb->prepare('DELETE FROM ' . Schema::table(Schema::LIVE_TABLES[2]) . ' WHERE id = %d', $meine['satz']));

$meine = ['knoten' => [], 'satz' => null, 'halter' => null];

check(
    'und nach dem Wegraeumen ist der Bestand wieder wie vorher',
    zweiBesitzer() === $vorher
);

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
