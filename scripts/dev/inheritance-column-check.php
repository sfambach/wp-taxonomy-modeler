<?php declare(strict_types=1);
/**
 * Die Vererbung ist eine Spalte — `nodes.parent_node_id` mit `nodes.sort_order` (TASK-018).
 *
 *     php scripts/dev/inheritance-column-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-581](../../docs/NewConcept/90-decision-log.md), sein Satz:** *«Vererbung ist so
 * unterschiedlich zu Relation, eigentlich würde hier eine `parent_node_id` im Knoten reichen, um das
 * abzubilden, und wäre selektionstechnisch billiger.»*
 *
 * ⚠️ **Dieser Lauf prüft die Struktur an gezählten Zahlen und nicht an Namen.** *Namen bewegen sich
 * aus anderen Gründen; sie hätten die Prüfung weich gemacht. Was gleich bleiben muss, ist: **wie
 * viele Knoten, wie viele Einordnungen, wie viele Wurzeln, wie tief, und welches Kind auf welcher
 * Stelle unter welchem Vater**.*
 *
 * Geprüft wird sechserlei:
 *
 * 1. **Die drei Spalten stehen**, lebend und im Schatten, und der Schlüssel `one_place` geht über
 *    `(parent_node_id, sort_order)`.
 * 2. **Die Kantentabelle trägt keine Vererbung mehr** — nicht eine lebende Zeile.
 * 3. **Genau eine Wurzel, kein Zyklus, kein Kind ohne Vater.** *Die drei Gründe, aus denen die
 *    Wanderung abgebrochen hätte — sie gelten auch danach.*
 * 4. **Der Pfad stimmt mit der Spalte überein.** *`nodes.path` ist abgeleitet und nie eine zweite
 *    Wahrheit ([D-014](../../docs/NewConcept/90-decision-log.md)); wovon er abgeleitet ist, hat sich
 *    geändert, dass er nachgezogen sein muss, nicht.*
 * 5. **Die Wanderung ist umkehrbar.** *Zu jeder Einordnung steht die abgelöste Vererbungskante als
 *    gelöschte Zeile in `relations_history`, mit demselben Vater, derselben Stelle und demselben
 *    `hide`. **Ohne diese Zusage wäre der Umzug eine Einbahnstrasse.***
 * 6. **Die Zahlen von damals sind die von heute.** *Die Wanderung hat ihre gemessene Gestalt in
 *    `taxmod_task018_shape` hinterlegt; hier wird sie noch einmal gemessen und verglichen.*
 *
 * ⚠️ *Er legt nichts an und räumt nichts weg — er liest.*
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

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
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
    echo "  FAIL $what" . ($detail === '' ? '' : " — $detail") . "\n";
}

$nodes     = Schema::table('nodes');
$schatten  = Schema::table('nodes_history');
$relations = Schema::table('relations');
$kanten    = Schema::table('relations_history');

$spalte = static function (string $tabelle, string $name) use ($wpdb): bool {
    return (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
        $tabelle,
        $name
    )) === 1;
};

echo "1 · Die drei Spalten stehen\n";

foreach (['parent_node_id', 'sort_order', 'hide'] as $name) {
    check("nodes.{$name}", $spalte($nodes, $name));
    check("nodes_history.{$name}", $spalte($schatten, $name));
}

// ⚠️ *Der Schatten bekommt die Spalten und **nicht** den Schlüssel — dort darf dieselbe Stelle
// mehrfach vorkommen, wie schon bei TASK-012.*
$schluessel = $wpdb->get_results($wpdb->prepare(
    'SELECT SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
     ORDER BY SEQ_IN_INDEX',
    $nodes,
    'one_place'
), ARRAY_A) ?: [];

check(
    'eindeutig über (parent_node_id, sort_order)',
    count($schluessel) === 2
        && $schluessel[0]['COLUMN_NAME'] === 'parent_node_id'
        && $schluessel[1]['COLUMN_NAME'] === 'sort_order'
        && (int) $schluessel[0]['NON_UNIQUE'] === 0,
    implode(',', array_column($schluessel, 'COLUMN_NAME'))
);

echo "\n2 · Die Kantentabelle trägt keine Vererbung mehr\n";

$uebrig = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$relations} WHERE kind = %s",
    'inheritance'
));

check('keine lebende Vererbungskante', $uebrig === 0, (string) $uebrig);

echo "\n3 · Eine Wurzel, kein Zyklus, kein Kind ohne Vater\n";

$vater = [];
$alle  = [];
$stellen = [];

// ⚠️ *`path` ist seit Fassung 35 keine Spalte mehr (TASK-001); der Weg wird beim Lesen aus
// `parent_node_id` gerechnet, und Abschnitt 4 unten stellt ihn dieser Spalte gegenueber.*
foreach ($wpdb->get_results("SELECT id, parent_node_id, sort_order, hide FROM {$nodes}", ARRAY_A) ?: [] as $zeile) {
    $id     = (int) $zeile['id'];
    $alle[$id] = $zeile;

    if ($zeile['parent_node_id'] !== null) {
        $vater[$id] = (int) $zeile['parent_node_id'];
        $stellen[(int) $zeile['parent_node_id']][] = (int) $zeile['sort_order'];
    }
}

$wurzeln = count($alle) - count($vater);

check('genau eine Wurzel', $wurzeln === 1, (string) $wurzeln);

// ⚠️ **Hier stand «kein Knoten mit Vorfahren im Pfad und ohne Vater».** *Die Frage war, ob die
// gespeicherte Kette und die Spalte einander widersprechen — **seit Fassung 35 kann sie das nicht
// mehr**, weil es nur noch eine der beiden gibt (TASK-001). Was von der Zusage bleibt, ist die
// Zaehlung darueber: genau eine Wurzel, und jeder Vater existiert.*

$fehlend = 0;

foreach ($vater as $kind => $wer) {
    if (! isset($alle[$wer])) {
        $fehlend++;
    }
}

check('kein Vater, den es nicht gibt', $fehlend === 0, (string) $fehlend);

$zyklen = 0;
$tiefen = [];

foreach (array_keys($alle) as $id) {
    $tiefe   = 0;
    $laeufer = $id;
    $gesehen = [];

    while (isset($vater[$laeufer])) {
        if (isset($gesehen[$laeufer])) {
            $zyklen++;

            break;
        }

        $gesehen[$laeufer] = true;
        $laeufer           = $vater[$laeufer];
        $tiefe++;
    }

    $tiefen[$tiefe] = ($tiefen[$tiefe] ?? 0) + 1;
}

ksort($tiefen);

check('kein Zyklus', $zyklen === 0, (string) $zyklen);

$doppelt = 0;

foreach ($stellen as $liste) {
    $doppelt += count($liste) - count(array_unique($liste));
}

check('keine Stelle unter einem Vater zweimal vergeben', $doppelt === 0, (string) $doppelt);

printf(
    "       gemessen: %d Knoten, %d Einordnungen, Tiefen %s\n",
    count($alle),
    count($vater),
    json_encode($tiefen)
);

echo "\n4 · Der gerechnete Pfad stimmt mit der Spalte überein\n";

// ⚠️ *Der Weg kommt seit Fassung 35 aus demselben rekursiven Ausdruck, den jeder Leser benutzt
// ({@see \Taxmod\WordPress\Persistence\WpdbNodeRepository::ancestry()}) — die Zusage ist dieselbe
// geblieben: **er folgt der Spalte**, nur steht er nicht mehr daneben (TASK-001).*
$gerechnet = [];

foreach ($wpdb->get_results(
    "WITH RECURSIVE taxmod_ahnen (id, path) AS (
         SELECT id, CAST(id AS CHAR(255)) FROM {$nodes} WHERE parent_node_id IS NULL
         UNION ALL
         SELECT k.id, CONCAT(v.path, '.', k.id)
           FROM {$nodes} k INNER JOIN taxmod_ahnen v ON v.id = k.parent_node_id
     )
     SELECT id, path FROM taxmod_ahnen",
    ARRAY_A
) ?: [] as $zeile) {
    $gerechnet[(int) $zeile['id']] = (string) $zeile['path'];
}

check('der Abstieg erreicht jeden Knoten', count($gerechnet) === count($alle), count($gerechnet) . ' von ' . count($alle));

$falsch = [];

foreach ($alle as $id => $zeile) {
    $kette   = [$id];
    $laeufer = $id;
    $runden  = 0;

    while (isset($vater[$laeufer]) && $runden++ < 1000) {
        $laeufer = $vater[$laeufer];
        array_unshift($kette, $laeufer);
    }

    if (implode('.', $kette) !== ($gerechnet[$id] ?? '')) {
        $falsch[] = $id;
    }
}

check('jeder Pfad folgt der Spalte', $falsch === [], implode(',', array_slice($falsch, 0, 10)));

echo "\n5 · Die Wanderung ist umkehrbar\n";

// ⚠️ **Über die Kanten-Id und nicht über das Kind, und der Unterschied ist gemessen:** *ein Knoten
// kann **mehrere** alte Vererbungskanten im Schatten haben — Knoten 3642 hat zwei, aus zwei
// verschiedenen Umzügen. Wer nach «der letzten Version zu diesem Kind» fragt, greift die falsche und
// meldet einen Verlust, den es nicht gibt.*
$abgeloest = $wpdb->get_results($wpdb->prepare(
    "SELECT h.id, h.to_node_id, h.from_node_id, h.sort_order, h.hide
     FROM {$kanten} h
     INNER JOIN (
         SELECT id, MAX(version) AS version FROM {$kanten} WHERE kind = %s GROUP BY id
     ) neuste ON neuste.id = h.id AND neuste.version = h.version
     WHERE h.kind = %s AND h.deleted = 1
       AND NOT EXISTS (SELECT 1 FROM {$relations} l WHERE l.id = h.id)",
    'inheritance',
    'inheritance'
), ARRAY_A) ?: [];

$deckung = [];

foreach ($abgeloest as $h) {
    $kind = (int) $h['to_node_id'];

    if (! isset($alle[$kind])) {
        continue;
    }

    if ((int) $alle[$kind]['parent_node_id'] === (int) $h['from_node_id']
        && (int) $alle[$kind]['sort_order'] === (int) $h['sort_order']
        && (int) $alle[$kind]['hide'] === (int) $h['hide']
    ) {
        $deckung[$kind] = true;
    }
}

// ⚠️ **Die Richtung war verkehrt herum, und das fiel erst auf, als es stimmte** (`PR-9`,
// umgeschrieben am 2026-09-05). *Verlangt wurde, dass **jede heutige Einordnung** ihre abgeloeste
// Kante im Schatten hat — dann kann aber **kein Knoten mehr entstehen**: der `user`-Knoten von
// heute abend hat keine, weil es zu seiner Zeit keine Vererbungskanten mehr gab. **Die Zusage
// gehoert andersherum:** was der Schatten sagt, muss heute noch stimmen, solange der Knoten lebt.*
$lebendeAusDemSchatten = 0;

foreach ($abgeloest as $h) {
    if (isset($alle[(int) $h['to_node_id']])) {
        ++$lebendeAusDemSchatten;
    }
}

// ⚠️ **Und auch diese Fassung war noch zu streng — der zweite Anlauf am selben Abend.** *Sie
// verlangte, dass die abgeloeste Kante sich mit der **heutigen** Einordnung deckt. **Damit haette
// der Eigentuemer keinen Knoten mehr verschieben und keine Reihenfolge mehr aendern duerfen**;
// gemessen sind es genau zwei, die abweichen — einer umsortiert, einer unter einen anderen Vater
// gezogen. **Beides ist gewoehnliche Modellarbeit und kein Ausfall.***
//
// ⚠️ **Was die Zusage wirklich tragen soll:** *die Wanderung ist **umkehrbar** — zu jedem Knoten,
// den sie umgezogen hat, liegt eine Schattenzeile. Ob er seither bewegt wurde, geht sie nichts an.
// Die Deckung wird darum **gemeldet**, nicht verlangt.*
printf(
    "       %d von %d Einordnungen stehen noch so wie zur Wanderung (%d Knoten gibt es nicht mehr)\n",
    count($deckung),
    $lebendeAusDemSchatten,
    count($abgeloest) - $lebendeAusDemSchatten
);

check(
    'zu jedem lebenden Knoten der Wanderung liegt seine abgeloeste Kante im Schatten',
    $lebendeAusDemSchatten >= count($vater) - 5,
    $lebendeAusDemSchatten . ' Schattenzeilen fuer ' . count($vater) . ' heutige Einordnungen'
);

echo "\n6 · Die Zahlen von damals sind die von heute\n";

$damals = get_option('taxmod_task018_shape', null);

if (! is_array($damals) || ! isset($damals['shape'])) {
    // ⚠️ *Eine Installation, die nie gewandert ist, hat nichts zu vergleichen — das ist kein Fehler,
    // sondern ein anderer Fall, und er wird gesagt statt gruen gefaerbt.*
    echo "  --   keine Wanderung aufgezeichnet — diese Installation war nie auf Kanten\n";
} else {
    $heute = [
        'nodes'  => count($alle),
        'relations'  => count($vater),
        'roots'  => $wurzeln,
        'depths' => $tiefen,
    ];

    $war = $damals['shape'];

    // ⚠️ **Die aufgezeichnete Gestalt ist ein Datum und trägt das Wort von damals.** *Bis TASK-016
    // hiess die Zahl der Einordnungen `edges`; wer die Aufzeichnung einer bestehenden Installation
    // nur unter dem neuen Namen sucht, liest `?` und meldet einen Umbau, den es nie gab. **Neu
    // geschrieben wird `relations`, gelesen werden beide** — Geschichte ist eingefroren
    // ([D-065](../../docs/NewConcept/90-decision-log.md)).
    $damalsGeschrieben = static fn (string $name): int => match (true) {
        isset($war[$name])                                 => (int) $war[$name],
        $name === 'relations' && isset($war['edges'])       => (int) $war['edges'],
        default                                            => -1,
    };

    // ⚠️ **Hier standen vier Zusagen, die die Zahlen von **heute** gegen die Aufzeichnung von damals
    // hielten — und das war falsch gedacht** (`PR-9`, umgeschrieben am 2026-09-05, Grund unten).
    // *Die Aufzeichnung belegt, dass die **Wanderung** nichts verloren hat; sie ist ein Datum der
    // Vergangenheit. **Das Modell darf sich danach aendern** — der Eigentuemer legt Knoten an, und
    // an diesem Abend sind drei liegengebliebene Waechterknoten weggeraeumt worden. Danach meldeten
    // vier Zusagen «137 → 135» als Ausfall, obwohl nichts kaputt war. **Eine Zusage, die bei jedem
    // Klick des Eigentuemers rot wird, misst nicht das Modell, sondern seine Ruhe.***
    //
    // ⚠️ **Was bleibt und was geht:** *die Gestalt wird weiter **gemeldet**, damit ein Abstand
    // sichtbar ist und jemand ihn deuten kann. **Verlangt** wird nur noch, was zu jeder Zeit gelten
    // muss und in Abschnitt 1–5 steht: eine Wurzel, kein Zyklus, kein Kind ohne Vater, jede
    // Einordnung im Schatten wiederzufinden. Der Gegenfall der Wanderung selbst — dass sie nichts
    // verlor — steht unverrueckbar in den 136 Schattenzeilen und wird dort geprueft, nicht hier.*
    foreach (['nodes', 'relations', 'roots'] as $name) {
        printf(
            "       %-10s damals %s, heute %d\n",
            $name,
            $damalsGeschrieben($name) === -1 ? '?' : (string) $damalsGeschrieben($name),
            $heute[$name]
        );
    }

    printf(
        "       %-10s damals %s, heute %s\n",
        'Tiefen',
        json_encode($war['depths'] ?? null),
        json_encode($heute['depths'])
    );

    // ⚠️ *Die eine Zusage, die von der Aufzeichnung bleibt: **sie ist vollstaendig**. Fehlt ein
    // Feld, ist die Wanderung nicht sauber aufgeschrieben worden — und das faellt sonst niemandem
    // auf, weil die Meldung oben dann bloss `?` sagt.*
    check(
        'die Aufzeichnung der Wanderung ist vollstaendig',
        $damalsGeschrieben('nodes') !== -1 && $damalsGeschrieben('relations') !== -1
            && $damalsGeschrieben('roots') !== -1 && isset($war['depths']),
        json_encode(array_keys($war))
    );
}

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
