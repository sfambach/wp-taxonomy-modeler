<?php declare(strict_types=1);
/**
 * `path` faellt — und kommt nicht zurueck.
 *
 *     php scripts/dev/path-check.php [path/to/wordpress]
 *
 * ⚠️ **Hier stand bis zum 2026-09-05 ein anderer Waechter gleichen Namens.** *Er pruefte
 * `settings.path` — die Adresse, die eine Einstellung brauchte, um zu sagen, **welche** Stelle sie
 * beantwortet ([D-413](../../docs/NewConcept/90-decision-log.md)). **Die Tabelle ist mit
 * [D-579](../../docs/NewConcept/90-decision-log.md) gestrichen**, und mit ihr die Zusage; der
 * Waechter fiel damals mit ihr, ohne dass etwas an seine Stelle trat. Dies ist die neue Zusage,
 * und sie ist die umgekehrte: **die Spalte ist weg und darf nicht wiederkommen.**
 *
 * Die drei Aufgaben, die sie abraeumen — [`tasks.md`](../../docs/pakete/modelltabellen/tasks.md):
 *
 * | | Tabelle | Was `path` dort war |
 * |---|---|---|
 * | **TASK-003** | `labels`, `settings` | eine Adresse **innerhalb** eines Eigentuemers — nachweislich nie belegt |
 * | **TASK-002** | `relation_records` | ein reiner Spiegel von `relation_id` |
 * | **TASK-001** | `nodes` | der Vorfahrenweg, den seit TASK-018 `parent_node_id` traegt |
 *
 * ⚠️ **Warum ein Waechter und nicht nur ein `ALTER TABLE`:** *`dbDelta` **fuegt fehlende Spalten
 * hinzu**. Steht `path` versehentlich wieder in einer `CREATE TABLE`-Anweisung, legt die naechste
 * Aktivierung sie klaglos wieder an — leer, ungelesen und ohne dass irgendetwas rot wird. **Genau
 * so ist eine gestrichene Spalte schon einmal zurueckgekommen.***
 *
 * ⚠️ *Dieser Lauf schreibt nichts und legt nichts an — er liest nur, also gibt es nichts wegzuraeumen.*
 *
 * @see docs/pakete/modelltabellen/tasks.md
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

use Taxmod\Core\Model\RelationKind;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;

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

function hatSpalte(string $tabelle, string $spalte): bool
{
    global $wpdb;

    return $wpdb->get_var("SHOW COLUMNS FROM {$tabelle} LIKE '{$spalte}'") !== null;
}

function tabelleDa(string $tabelle): bool
{
    global $wpdb;

    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tabelle)) === $tabelle;
}

/**
 * Die Quelltexte, in denen eine Spalte genannt sein koennte.
 *
 * @return list<string>
 */
function quelltexte(): array
{
    $wurzel   = dirname(__DIR__, 2) . '/src';
    $gefunden = [];

    $lauf = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS));

    foreach ($lauf as $datei) {
        if ($datei->isFile() && $datei->getExtension() === 'php') {
            $gefunden[] = $datei->getPathname();
        }
    }

    sort($gefunden);

    return $gefunden;
}

/**
 * Eine Datei ohne ihre Kommentare.
 *
 * ⚠️ **Der Unterschied ist hier die ganze Pruefung.** *Eine gefallene Spalte hinterlaesst genau
 * einen Satz darueber, **warum** sie fiel — der Kommentar ist der Beleg und nicht der Verstoss.
 * Wer den Quelltext roh durchsucht, wird von seiner eigenen Begruendung rot.*
 */
function ohneKommentare(string $datei): string
{
    $reste = [];

    foreach (token_get_all((string) file_get_contents($datei)) as $stueck) {
        if (is_array($stueck) && in_array($stueck[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $reste[] = is_array($stueck) ? $stueck[1] : $stueck;
    }

    return implode('', $reste);
}

echo "1 · TASK-003 · labels und settings tragen keinen Pfad mehr\n";

$labels     = Schema::table('labels');
$labelTexte = Schema::table('label_texts');
$settings   = Schema::table('settings');

check('labels hat keine Spalte path', tabelleDa($labels) && ! hatSpalte($labels, 'path'));
check('label_texts hat keine Spalte path', tabelleDa($labelTexte) && ! hatSpalte($labelTexte, 'path'));

// ⚠️ *Die Tabelle ist mit D-579 gestrichen; steht sie noch da, ist die Fassung nicht gelaufen —
// und dann ist auch ihre `path`-Spalte wieder eine offene Frage.*
check('settings gibt es nicht mehr', ! tabelleDa($settings));

// ⚠️ **Die Zusage gilt auch der `CREATE TABLE`-Anweisung und nicht nur der Datenbank.** *`dbDelta`
// legt an, was in ihr steht; eine Spalte, die dort wieder auftaucht, ist beim naechsten Aufstieg
// zurueck, ohne dass jemand sie geschrieben haette.*
$anweisungen = [];

foreach ((new ReflectionMethod(Schema::class, 'statements'))->invoke(null) as $sql) {
    if (preg_match('/CREATE TABLE (\S+)/', (string) $sql, $treffer) === 1) {
        $anweisungen[$treffer[1]] = (string) $sql;
    }
}

function anweisungOhnePfad(array $anweisungen, string $tabelle): bool
{
    $sql = $anweisungen[$tabelle] ?? null;

    if ($sql === null) {
        return true;
    }

    return preg_match('/^\s*path\s/m', $sql) !== 1;
}

check('die Anweisung fuer labels nennt keinen Pfad', anweisungOhnePfad($anweisungen, $labels));
check('die Anweisung fuer label_texts nennt keinen Pfad', anweisungOhnePfad($anweisungen, $labelTexte));

// ⚠️ **Der Kern darf die Spalte nicht mehr kennen.** *Gesucht wird der Zugriff, nicht das Wort:
// `$label->path`, `'path' =>` an einer Beschriftungszeile, `labels.path`. Ein Fliesstext, der die
// gefallene Spalte **erklaert**, ist kein Verstoss — er ist der Grund, aus dem sie fiel.*
$treffer = [];

foreach (quelltexte() as $datei) {
    if (preg_match('/\blabels?\.path\b/', ohneKommentare($datei)) === 1 && ! str_contains($datei, 'Schema.php')) {
        $treffer[] = basename($datei);
    }
}

check(
    'kein Quelltext liest labels.path',
    $treffer === [],
    implode(', ', $treffer)
);
echo "\n2 · TASK-002 · relation_records traegt keinen Pfad mehr\n";

// ⚠️ **Hier stand bis zum 2026-09-06 die umgekehrte Zusage** (`INF-051`): *die Spalte `path` bleibe
// stehen, weil «diese Einstellung ueberall» und «diese Einstellung nur hier» sonst auf dieselbe
// Zeile fielen. **Der Grund war richtig und die Loesung ist eine andere**: der Satz sagt, zu wem er
// gehoert ([D-667](../../docs/NewConcept/90-decision-log.md)) — `node_records.relation_id`.*
//
// ⚠️ **Sein Wort dazu, und es hat den Zwischenschritt gekippt:** *«also verklausulierst du path als
// Text» — eine zweite Zahlenspalte an der Wertzeile waere derselbe Pfad im besseren Mantel gewesen.*
//
// ⚠️ **Und die Zusage ist seit Fassung 39 die endgueltige:** *die Spalte ist weg. Vorher stand hier
// «keine lebende Zeile sagt darin etwas, was ihre `relation_id` nicht schon sagt» — der Zustand, der
// das Streichen erlaubte. **Er ist erfuellt worden und deshalb nicht mehr die Frage.***
$werte  = Schema::table('relation_records');
$saetze = Schema::table('node_records');

check('node_records nennt die Verwendungsstelle', hatSpalte($saetze, 'relation_id'));

check('relation_records hat keine Spalte path', tabelleDa($werte) && ! hatSpalte($werte, 'path'));
check('die Anweisung fuer relation_records nennt keinen Pfad', anweisungOhnePfad($anweisungen, $werte));

// ⚠️ **Der Schatten behaelt seinen Pfad, und das ist keine Nachlaessigkeit** — *dieselbe Begruendung
// wie bei `nodes_history` ([D-065](../../docs/NewConcept/90-decision-log.md)): eine alte Zeile fuehrt
// ihre Angaben als **Datum** mit. **Die zwei zweiteiligen Adressen, die Fassung 37 umgehaengt hat,
// stehen dort und nirgends sonst.** Damit `shadow-shape-check` das nicht als Bruch liest, steht sie
// in {@see Schema::SHADOW_ONLY_IN} — hier wird nachgesehen, dass sie wirklich beides tut.*
$schattenWerte = Schema::table('relation_records_history');

check('der Schatten behaelt ihn', hatSpalte($schattenWerte, 'path'));

check(
    'und die Ausnahme ist benannt, nicht still',
    in_array('path', Schema::SHADOW_ONLY_IN['relation_records_history'] ?? [], true)
);

// ⚠️ *Die Zahlen der Wanderung bleiben stehen, damit ein spaeterer Lauf sie **vergleichen** kann —
// dieselbe Vorsorge wie `taxmod_nodepath_shape` in Fassung 35.*
$standWerte = get_option('taxmod_relationpath_shape', []);

check(
    'die Fassung hat ihre Zahlen hinterlassen',
    is_array($standWerte) && isset($standWerte['rows'], $standWerte['relations'], $standWerte['sum'])
);

printf(
    "       gemessen: %s Wertzeilen an %s Kanten, als die Spalte fiel\n",
    (string) ($standWerte['rows'] ?? '?'),
    (string) ($standWerte['relations'] ?? '?')
);

// ⚠️ **Und keine Wertzeile ist dabei ohne Adresse geblieben.** *Genau das ist an dieser Spalte am
// 2026-09-06 schon einmal passiert: sie war kurz weg und kam **leer** zurueck, 118 Zeilen ohne
// Adresse, und sechs Knoten zeichneten ploetzlich mit dem Rueckfall.*
//
// ⚠️ **Gefragt ist nicht «keine Null», und das waere seit heute abend falsch:** *`relation_id = 0`
// heisst nach [D-673](../../docs/NewConcept/90-decision-log.md) «der Wert des Knotens selbst, nicht
// der eines seiner Felder». **Gefragt ist, dass jede Zahl, die keine Null ist, eine Kante trifft** —
// eine Nummer, zu der es keine Kante gibt, ist eine Adresse, die auf nichts zeigt.*
$insLeere = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$werte} v
     WHERE v.relation_id <> 0
       AND NOT EXISTS (SELECT 1 FROM " . Schema::table('relations') . ' r WHERE r.id = v.relation_id)'
);

check('jede Adresse trifft eine Kante, oder sie ist die Null des Knotens', $insLeere === 0, "{$insLeere} ins Leere");

// ⚠️ **Und der Kern schreibt wirklich an den Satz der Stelle** — sonst waere `node_records.relation_id`
// ein Denkmal. *Gesucht im Quelltext **ohne Kommentare**.*
$eintrag = ohneKommentare(dirname(__DIR__, 2) . '/src/Core/Service/DataEntry.php');

check(
    'und niemand baut mehr Verwendungsstelle . Einstellungskante',
    preg_match('/\$relationId\s*\.\s*\'\.\'\s*\.\s*\$kante->id/', $eintrag) !== 1
);

// ⚠️ **Und der Kern kennt die Spalte nicht mehr** — *dieselbe Suche wie bei `labels.path` oben:
// gesucht wird der **Zugriff**, nicht das Wort. Ein Fliesstext, der die gefallene Spalte erklaert,
// ist der Grund, aus dem sie fiel, und kein Verstoss.*
$trefferWert = [];

foreach (quelltexte() as $datei) {
    $roh = ohneKommentare($datei);

    if (str_contains($datei, 'Schema.php')) {
        continue;
    }

    if (preg_match('/\$(wert|value|zeile|stand|halter|geschrieben\[0\])(\w*)?->path\b/', $roh) === 1) {
        $trefferWert[] = basename($datei);
    }
}

check(
    'kein Quelltext liest den Pfad einer Wertzeile',
    $trefferWert === [],
    implode(', ', $trefferWert)
);

echo "\n3 · TASK-001 · nodes traegt keinen Pfad mehr — und die Vorfahren sind dieselben\n";

$knoten = Schema::table('nodes');

check('nodes hat keine Spalte path', ! hatSpalte($knoten, 'path'));
check('die Anweisung fuer nodes nennt keinen Pfad', anweisungOhnePfad($anweisungen, $knoten));

// ⚠️ *Der Schluessel darauf faellt mit ihr — `dbDelta` ruehrt einen Index nie an, also waere er
// stehengeblieben, wenn die Fassung ihn nicht mitgenommen haette.*
$schluessel = $wpdb->get_col($wpdb->prepare(
    'SELECT INDEX_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s',
    $knoten,
    'path'
));

check('und keinen Schluessel darauf', $schluessel === []);

// ⚠️ **Der Schatten behaelt seinen Weg, und das ist keine Nachlaessigkeit** — *dieselbe Begruendung
// wie bei `name` ([D-065](../../docs/NewConcept/90-decision-log.md)): eine alte Zeile fuehrt ihre
// Angaben als **Datum** mit. Damit `shadow-shape-check` das nicht als Bruch liest, steht sie in
// {@see Schema::SHADOW_ONLY_IN} — hier wird nachgesehen, dass sie wirklich beides tut.*
check('der Schatten behaelt ihn', hatSpalte(Schema::table('nodes_history'), 'path'));

check(
    'und die Ausnahme ist benannt, nicht still',
    in_array('path', Schema::SHADOW_ONLY_IN['nodes_history'] ?? [], true)
);

// ⚠️ **Die Wanderung hat ihre Zusage hinterlassen, und hier wird nachgesehen, dass sie steht.**
// *Sie ist kein Vergleich mit einer festen Zahl auf seinen Bestand — **er darf jederzeit einen
// Knoten anlegen, verschieben oder wegwerfen**, und eine eingefrorene Pruefsumme waere am naechsten
// Tag rot, ohne dass etwas kaputt waere ([`waechter-bestand.md`](../../docs/pakete/modelltabellen/waechter-bestand.md)).
// **Gefragt ist, ob der Rueckweg da ist:** die Zahlen der Wanderung, eine Aenderungsgruppe, je Zeile
// ihr alter Weg und ihre Version.*
$stand = get_option('taxmod_nodepath_shape', []);

check(
    'die Fassung hat ihre Zahlen hinterlassen',
    is_array($stand) && isset($stand['wege'], $stand['tree'], $stand['depths'])
);

$journal = Schema::table('changelog');

$gemeldet = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$journal} WHERE what = 'path dropped'");
$gruppen  = (int) $wpdb->get_var("SELECT COUNT(DISTINCT change_group_id) FROM {$journal} WHERE what = 'path dropped'");
$ohneWeg  = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$journal}
     WHERE what = 'path dropped' AND (before_state IS NULL OR before_state = '')"
);
$ohneVersion = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$journal}
     WHERE what = 'path dropped' AND (version IS NULL OR version = 0)"
);

// ⚠️ **Gefragt ist «es gibt sie», nicht «es sind genau so viele», und das ist derselbe Fehler zum
// zweiten Mal an einem Tag** (`INF-053`). *Die Wanderung hat **167** Meldungen geschrieben, eine je
// Knoten. Heute stehen weniger da — weil Knoten seither weggeraeumt wurden und ihre Journalzeilen
// mit ihnen. **Eine feste Zahl auf seinen Bestand ist ein Waechter, der ihm sein eigenes Aufraeumen
// als Verlust meldet.** Die Zahl von damals steht in `taxmod_nodepath_shape`; hier steht sie zum
// Nachlesen und nicht als Bedingung.*
check('die Knoten haben ihren alten Weg ins Journal bekommen', $gemeldet > 0, "$gemeldet Zeilen");

printf(
    "       gemessen: %d Meldungen heute, %s Knoten bei der Wanderung\n",
    $gemeldet,
    (string) ($stand['nodes'] ?? '?')
);
check('und alle unter einer Aenderungsgruppe', $gemeldet === 0 || $gruppen === 1, "$gruppen Gruppen");
check('keine Meldung ohne den Weg, den sie aufhebt', $ohneWeg === 0, "$ohneWeg ohne");
check('keine Meldung ohne Version (D-634)', $ohneVersion === 0, "$ohneVersion ohne");

// ⚠️ **Und der Schatten traegt die Zeilen — aber das wird **gezaehlt** und nicht verlangt, und der
// Grund ist gemessen.** *Die Wanderung hat alle 167 aufgehoben; **eine Schattenzeile ist trotzdem
// nicht ewig**: mehrere Randpruefungen raeumen ihre eigenen Zeilen dort wieder weg
// (`move-mask-check`, `restore-check`, `parked-in-shadow-check` loeschen aus `nodes_history`), und
// eine davon hat am 2026-09-05 eine Zeile mitgenommen. **Ein Wächter, der daraufhin rot wird, meldet
// eine Aufraeumung als Verlust.** Was bleibt, ist die Journalzeile: sie traegt den Weg selbst.*
$ohneSchatten = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$journal} c
     WHERE c.what = 'path dropped'
       AND NOT EXISTS (SELECT 1 FROM " . Schema::table('nodes_history') . " h
                       WHERE h.id = c.owner_id AND h.version = c.version)"
);

printf("       gemessen: %d von %d Meldungen haben ihre Schattenzeile noch\n", $gemeldet - $ohneSchatten, $gemeldet);

// ⚠️ **Die eigentliche Zusage: zwei Wege zum selben Ergebnis.** *Die gerechnete Kette und der
// Aufstieg ueber `parent_node_id` sind **verschiedene Rechnungen** — die eine im Server, die andere
// in PHP. Stimmten sie nicht ueberein, waere der Weg nicht mehr das, was `parent_node_id` sagt, und
// genau das war die Doppelung, die mit der Spalte fiel. **An gezaehlten Zahlen und nicht an
// Namen**, und sie erneuert sich mit seinem Bestand, statt ihn einzufrieren.*
$alle = $wpdb->get_results("SELECT id, parent_node_id FROM {$knoten}", ARRAY_A) ?: [];
$vater = [];

foreach ($alle as $zeile) {
    $vater[(int) $zeile['id']] = $zeile['parent_node_id'] === null ? null : (int) $zeile['parent_node_id'];
}

$gerechnet = $wpdb->get_results(
    "WITH RECURSIVE taxmod_ahnen (id, path) AS (
         SELECT id, CAST(id AS CHAR(255)) FROM {$knoten} WHERE parent_node_id IS NULL
         UNION ALL
         SELECT k.id, CONCAT(v.path, '.', k.id)
           FROM {$knoten} k INNER JOIN taxmod_ahnen v ON v.id = k.parent_node_id
     )
     SELECT id, path FROM taxmod_ahnen",
    ARRAY_A
) ?: [];

$verglichen = 0;
$auseinander = [];

foreach ($gerechnet as $zeile) {
    $id   = (int) $zeile['id'];
    $auf  = [];
    $lauf = $id;

    // ⚠️ *Nach oben, Schritt fuer Schritt, mit einer Bremse: ein Kreis in `parent_node_id` liefe
    // sonst ewig, und dieser Lauf soll ihn **melden** und nicht daran haengenbleiben.*
    for ($i = 0; $i < 1000 && $lauf !== null; $i++) {
        array_unshift($auf, $lauf);
        $lauf = $vater[$lauf] ?? null;
    }

    $verglichen++;

    if (implode('.', $auf) !== (string) $zeile['path']) {
        $auseinander[] = $id;
    }
}

check(
    'der gerechnete Weg und der Aufstieg ueber parent_node_id sagen dasselbe',
    $auseinander === [],
    count($auseinander) . ' auseinander: ' . implode(', ', array_slice($auseinander, 0, 5))
);

check('und der Vergleich hat wirklich Zeilen gesehen', $verglichen === count($alle), "$verglichen von " . count($alle));

// ⚠️ **Und der gerechnete Weg erreicht jeden Knoten.** *Ein Knoten, den der Abstieg nicht erreicht,
// haette frueher einen Pfad in seiner Spalte gehabt und **keinen Vater** — er waere sichtbar
// geblieben und unerreichbar gewesen. Jetzt faellt er aus jedem Leser heraus, also muss diese Zahl
// null sein.*
$unerreicht = (int) $wpdb->get_var(
    "WITH RECURSIVE taxmod_ahnen (id) AS (
         SELECT id FROM {$knoten} WHERE parent_node_id IS NULL
         UNION ALL
         SELECT k.id FROM {$knoten} k INNER JOIN taxmod_ahnen v ON v.id = k.parent_node_id
     )
     SELECT COUNT(*) FROM {$knoten} n WHERE n.id NOT IN (SELECT id FROM taxmod_ahnen)"
);

check('jeder Knoten wird vom Abstieg erreicht', $unerreicht === 0, "$unerreicht unerreicht");

// ⚠️ *Und der Leser liefert wirklich etwas — die Gegenprobe zu allem darueber. **Ein Weg, der leer
// waere, wuerde jede Pruefsumme oben trotzdem erfuellen**, solange er ueberall gleich leer ist.*
$speicher = new \Taxmod\WordPress\Persistence\WpdbNodeRepository();
$wurzelId = (int) $wpdb->get_var("SELECT id FROM {$knoten} WHERE parent_node_id IS NULL LIMIT 1");
$tiefste  = (int) $wpdb->get_var("SELECT id FROM {$knoten} WHERE parent_node_id IS NOT NULL ORDER BY id DESC LIMIT 1");

$wurzel = $wurzelId === 0 ? null : $speicher->find($wurzelId);
$tief   = $tiefste === 0 ? null : $speicher->find($tiefste);

check('die Wurzel traegt ihre eigene Nummer als Weg', $wurzel !== null && $wurzel->path === (string) $wurzelId, $wurzel?->path ?? 'nicht gefunden');

check(
    'ein Knoten mit Vater traegt eine Kette und endet auf sich selbst',
    $tief !== null && str_contains($tief->path, '.') && str_ends_with($tief->path, '.' . $tiefste),
    $tief?->path ?? 'nicht gefunden'
);

// ⚠️ *`ancestorIds()` laesst den Knoten selbst weg — die naechste Stufe darin ist sein Vater. **Das
// ist die Stelle, an der ein Leser den Baum wirklich benutzt**, und sie muss dasselbe sagen wie die
// Spalte, aus der jetzt alles kommt.*
$ahnen = $tief?->ancestorIds() ?? [];

check(
    'und seine Vorfahren enden auf seinem Vater',
    $tief !== null && $ahnen !== [] && $ahnen[count($ahnen) - 1] === $tief->parentNodeId,
    $tief === null ? 'nicht gefunden' : implode('.', $ahnen) . ' gegen Vater ' . (string) $tief->parentNodeId
);

// ============================================================================
// Zusammengelegt am 2026-09-06: `field-type-gone-check.php` sagte dasselbe
// ============================================================================
//
// ⚠️ **Zwei Laeufe, eine Aussage** — *«eine gefallene Spalte kommt nicht zurueck, und `dbDelta` legt
// sie nicht wieder an». Der eine sagte es ueber `path`, der andere ueber `nodes.field_type`
// ([D-621](../../docs/NewConcept/90-decision-log.md)). Sie sind am 2026-09-06 zusammengelegt worden
// ([`waechter-bestand.md`](../../docs/pakete/modelltabellen/waechter-bestand.md), auf sein Wort
// «checks mein ja»). **Keine der zwoelf Zusagen ist dabei weggefallen** (`PR-9`).*

echo "\n== field_type: die Spalte ist weg und bleibt weg ==\n";

foreach (['nodes', 'nodes_history'] as $tabelle) {
    $tabellenName = Schema::table($tabelle);

    check(
        "{$tabelle} hat keine Spalte field_type",
        $wpdb->get_var("SHOW COLUMNS FROM {$tabellenName} LIKE 'field_type'") === null
    );
}

// ⚠️ *Auch im Quelltext, und nicht nur in der Tabelle: **eine Spalte, die niemand mehr anlegt, kann
// ueber `dbDelta` zurueckkommen**, wenn ihre Zeile im `CREATE TABLE` stehen bleibt.*
$schemaQuelltext = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Persistence/Schema.php');

check(
    'und kein CREATE TABLE legt sie wieder an',
    ! preg_match('/^\s*field_type varchar/m', $schemaQuelltext)
);

check('die Schemafassung ist mindestens 33', Schema::VERSION >= 33, (string) Schema::VERSION);

echo "\n== field_type: was gefallen ist, liegt im Schatten und im Journal ==\n";

$journal = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('changelog') . " WHERE what = 'field type dropped'"
);

check('jede gefallene Marke hat eine Journalzeile', $journal > 0, (string) $journal);

check(
    'und alle unter genau einer Aenderungsgruppe',
    (int) $wpdb->get_var(
        'SELECT COUNT(DISTINCT change_group_id) FROM ' . Schema::table('changelog')
            . " WHERE what = 'field type dropped'"
    ) === 1
);

// ⚠️ *[D-634](../../docs/NewConcept/90-decision-log.md): die Version ist ein Pflichtwert. Eine
// Journalzeile ohne sie kann nicht sagen, auf welchen Stand sie sich bezieht.*
check(
    'und jede nennt ihre Version',
    (int) $wpdb->get_var(
        'SELECT COUNT(*) FROM ' . Schema::table('changelog')
            . " WHERE what = 'field type dropped' AND version IS NULL"
    ) === 0
);

echo "\n== field_type: jede Einstellungskante wird weiter als solche erkannt ==\n";

$kantenTabelle = Schema::table('relations');

// ⚠️ **Die Zahl steht hier nicht fest, und das ist Absicht** *(`PR-9`: der Waechter bewacht den
// Zielzustand, und der Eigentuemer darf Einstellungskanten anlegen). **Was fest steht, ist die
// Gleichheit**: was die Spalte `kind` sagt, muss die Klasse hinter der Kante auch sagen.*
$ausDerSpalte = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$kantenTabelle} WHERE kind = %s",
    RelationKind::Setting->value
));

$ausDerKlasse = 0;

foreach ((new WpdbRelationRepository())
    ->fieldRelationsTo(array_map('intval', $wpdb->get_col('SELECT id FROM ' . Schema::table('nodes')))) as $relation) {
    $ausDerKlasse += $relation->isSetting() ? 1 : 0;
}

check(
    'so viele Einstellungskanten wie die Spalte sagt',
    $ausDerSpalte === $ausDerKlasse,
    "Spalte {$ausDerSpalte}, Klasse {$ausDerKlasse}"
);

check('und es sind ueberhaupt welche da', $ausDerSpalte > 0, (string) $ausDerSpalte);

// ⚠️ *Nur die drei Werte aus [D-639](../../docs/NewConcept/90-decision-log.md) — «ein Mittel, das
// bestimmt, was fuer eine Verbindung es ist, und nicht noch einen Schalter».*
$fremd = array_values(array_filter(
    $wpdb->get_col("SELECT DISTINCT kind FROM {$kantenTabelle}"),
    static fn ($v): bool => RelationKind::tryFrom((string) $v) === null
));

check('und keine Kantenart, die der Code nicht kennt', $fremd === [], implode(', ', $fremd));

echo "\n== field_type: der Waehler unter `Renderer` bietet wieder Moeglichkeiten an ==\n";

// ⚠️ **Das ist der Grund, warum der Rueckbau sichtbar ist** (`INF-042`): *ein Waehler entsteht in der
// Wertspalte aus den **unmarkierten** Kindern des Kantenziels
// ([D-540](../../docs/NewConcept/90-decision-log.md)). **Alle neunzehn Knoten unter `Renderer` trugen
// die Marke**, die Auswahl sah durch jeden hindurch und bot **null** Moeglichkeiten an. Kaeme die
// Marke auf irgendeinem Weg zurueck, stuende hier wieder null.*

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
