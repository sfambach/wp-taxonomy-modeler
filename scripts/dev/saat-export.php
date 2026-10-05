<?php declare(strict_types=1);

/**
 * Den gewachsenen Baum abziehen — **einmal gelesen, als `data/saat.json` hingelegt**.
 *
 *     php scripts/dev/saat-export.php [path/to/wordpress]
 *
 * ⚠️ **Der Vollzug von [D-600](../../docs/NewConcept/90-decision-log.md):** *«eine Neuinstallation
 * entsteht künftig aus einem Abbild des gewachsenen Baums».* **Sein Auftrag am 2026-09-06 zu
 * `INF-063`:** *«wir sollten den aktuellen bestand einfrieren lass aber alles mit `__` weg das ist
 * dir».*
 *
 * ```mermaid
 * flowchart LR
 *   B["sein Bestand"] --> A["dieser Lauf, lesend"]
 *   A -->|"ohne __, ohne Papierkorb"| J["data/saat.json"]
 *   J -->|"SeedImage::importOnce()"| F["frische Installation"]
 * ```
 *
 * ⚠️ **Ein Lesen und kein Schreiben.** *Die Klammer aus `lib/no-write.php` liegt trotzdem darum —
 * nicht weil dieser Lauf schriebe, sondern weil `no-model-write-check.php` sie von jedem Lauf mit
 * `wp-load` verlangt und eine Ausnahme mit Begründung teurer wäre als die Klammer.*
 *
 * ⚠️ **Was draussen bleibt, und warum jeweils:**
 *
 * | draussen | Grund |
 * |---|---|
 * | alles mit Präfix `__` und was darunter hängt | Wiesen von Prüfläufen — sein Wort |
 * | was unter dem Papierkorb hängt | weggeworfen heisst weggeworfen; der Papierkorb selbst bleibt |
 * | Kanten an einen ausgeschlossenen Knoten | eine Kante ins Leere ist keine Kante |
 * | Sätze und Wertzeilen daran | ein Satz ohne seinen Knoten ist ein Waisenkind |
 * | Beschriftungen, auf die nichts mehr zeigt | dieselbe Regel, die `Schema::forgetOrphanLabels()` anwendet |
 *
 * @see src/WordPress/Persistence/SeedImage.php
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
require rtrim($root, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\WordPress\Persistence\Query;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeedImage;

global $wpdb;

/** Der Präfix, der «nicht vom Menschen» sagt — derselbe wie in `geruest.php`. */
const VORSATZ = '__';

// ─── 1 · Welche Knoten bleiben ────────────────────────────────────────────────

$knoten = Query::rows('Knoten lesen', 'SELECT id, parent_node_id, label_id FROM ' . Schema::table('nodes'));

/** @var array<int, list<int>> Vater-Id => Kinder-Ids. */
$kinder = [];

foreach ($knoten as $zeile) {
    $kinder[(int) $zeile['parent_node_id']][] = (int) $zeile['id'];
}

/** Die Namen je Beschriftung — ein Knoten heisst `__…`, wenn irgendeine seiner Zeilen es tut. */
$namen = [];

foreach (Query::rows('Namen lesen', 'SELECT label_id, text_name FROM ' . Schema::table('label_texts')) as $zeile) {
    $namen[(int) $zeile['label_id']][] = (string) ($zeile['text_name'] ?? '');
}

$wurzelnDerAussortierung = [];

foreach ($knoten as $zeile) {
    foreach ($namen[(int) $zeile['label_id']] ?? [] as $name) {
        if (str_starts_with($name, VORSATZ)) {
            $wurzelnDerAussortierung[] = (int) $zeile['id'];

            break;
        }
    }
}

// ⚠️ *Der Papierkorb **selbst** bleibt — er ist ein Rahmenwerksknoten, und eine frische
// Installation braucht ihn. Was darunter hängt, ist weggeworfen und wird nicht mitgesät.*
$papierkorb = (int) get_option('taxmod_trash_id', 0);

if ($papierkorb > 0) {
    foreach ($kinder[$papierkorb] ?? [] as $kind) {
        $wurzelnDerAussortierung[] = $kind;
    }
}

/** @var array<int, true> Die Ids, die draussen bleiben — samt allem, was darunter hängt. */
$draussen = [];
$stapel   = $wurzelnDerAussortierung;

while ($stapel !== []) {
    $id = array_pop($stapel);

    if (isset($draussen[$id])) {
        continue;
    }

    $draussen[$id] = true;

    foreach ($kinder[$id] ?? [] as $kind) {
        $stapel[] = $kind;
    }
}

$knotenIds = [];

foreach ($knoten as $zeile) {
    $id = (int) $zeile['id'];

    if (! isset($draussen[$id])) {
        $knotenIds[$id] = true;
    }
}

// ─── 2 · Die Zeilen einsammeln ────────────────────────────────────────────────

/**
 * Eine Tabelle lesen und Zeile für Zeile filtern.
 *
 * @param callable(array<string, ?string>): bool $behalten
 * @return list<array<string, ?string>>
 */
function abziehen(string $tabelle, callable $behalten): array
{
    $spalten = SeedImage::TABLES[$tabelle];

    // ⚠️ *Eine feste Spaltenliste, und sie muss die Tabelle noch ganz beschreiben — sonst zöge der
    // Abzug still eine Spalte weniger ab, als es sie gibt.*
    $fehlend = SeedImage::columnsAreComplete($tabelle);

    if ($fehlend !== []) {
        fwrite(STDERR, "Der Abzug kennt Spalten von {$tabelle} nicht: " . implode(', ', $fehlend) . "\n");

        exit(1);
    }

    $zeilen = Query::rows(
        "{$tabelle} abziehen",
        'SELECT ' . implode(', ', $spalten) . ' FROM ' . Schema::table($tabelle) . ' ORDER BY id'
    );

    return array_values(array_filter($zeilen, $behalten));
}

$nodes = abziehen('nodes', static fn (array $z): bool => isset($knotenIds[(int) $z['id']]));

$relations = abziehen('relations', static fn (array $z): bool =>
    isset($knotenIds[(int) $z['from_node_id']]) && isset($knotenIds[(int) $z['to_node_id']]));

$kantenIds = [];

foreach ($relations as $zeile) {
    $kantenIds[(int) $zeile['id']] = true;
}

/**
 * Ob eine Adresse auf **den Knoten selbst** zeigt statt auf eine Verwendungsstelle.
 *
 * ⚠️ **`0` und `null` sagen hier dasselbe, und das ist gemessen, nicht vermutet**
 * ([D-673](../../docs/NewConcept/90-decision-log.md)): *`node_records.relation_id` steht 192-mal auf
 * `0` und kein einziges Mal auf `null`. Wer nur auf `null` prüft, wirft 192 von 194 Sätzen weg — der
 * erste Abzug tat genau das.*
 */
function amKnotenSelbst(?string $adresse): bool
{
    return $adresse === null || (int) $adresse === 0;
}

// ⚠️ *`relation_id` am Satz sagt, **wem** er gehört — dem Knoten selbst oder einer
// Verwendungsstelle ([D-667](../../docs/NewConcept/90-decision-log.md)). Zeigt sie auf eine Kante,
// die nicht mitkommt, kommt der Satz auch nicht mit.*
$nodeRecords = abziehen('node_records', static fn (array $z): bool =>
    isset($knotenIds[(int) $z['node_id']])
    && (amKnotenSelbst($z['relation_id']) || isset($kantenIds[(int) $z['relation_id']])));

$satzIds = [];

foreach ($nodeRecords as $zeile) {
    $satzIds[(int) $zeile['id']] = true;
}

$relationRecords = abziehen('relation_records', static fn (array $z): bool =>
    isset($satzIds[(int) $z['node_record_id']])
    && (amKnotenSelbst($z['relation_id']) || isset($kantenIds[(int) $z['relation_id']])));

// ⚠️ *Beschriftungen kommen mit, worauf ein mitkommender Knoten oder eine mitkommende Kante zeigt —
// dieselbe Regel, nach der {@see Schema::forgetOrphanLabels()} aufräumt.*
$beschriftungsIds = [];

foreach ([...$nodes, ...$relations] as $zeile) {
    if ($zeile['label_id'] !== null) {
        $beschriftungsIds[(int) $zeile['label_id']] = true;
    }
}

$labels     = abziehen('labels', static fn (array $z): bool => isset($beschriftungsIds[(int) $z['id']]));
$labelTexts = abziehen('label_texts', static fn (array $z): bool => isset($beschriftungsIds[(int) $z['label_id']]));

$tabellen = [
    'labels'           => $labels,
    'label_texts'      => $labelTexts,
    'nodes'            => $nodes,
    'relations'        => $relations,
    'node_records'     => $nodeRecords,
    'relation_records' => $relationRecords,
];

// ─── 3 · Die Optionen ─────────────────────────────────────────────────────────

$optionen  = [];
$unbekannt = [];

foreach (Query::rows(
    'Optionen lesen',
    "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'taxmod\\_%' ORDER BY option_name"
) as $zeile) {
    $name = (string) $zeile['option_name'];

    if (SeedImage::optionBelongs($name)) {
        $wert = (string) $zeile['option_value'];

        // ⚠️ *Eine Option, die auf eine ausgeschlossene Nummer zeigt, zeigt ins Leere — die drei
        // `taxmod_testast_*` sind genau dieser Fall.*
        if (SeedImage::optionCarriesId($name) && ! isset($knotenIds[(int) $wert]) && ! isset($kantenIds[(int) $wert])) {
            continue;
        }

        $optionen[$name] = $wert;

        continue;
    }

    if (! SeedImage::optionStaysOut($name)) {
        $unbekannt[] = $name;
    }
}

// ⚠️ **Eine neue Option ist eine Entscheidung und keine Ableitung** (`PR-4`): *sie gehört in den
// Abzug oder ausdrücklich nicht hinein, und dieser Lauf hört auf, bis jemand sie hinschreibt.*
if ($unbekannt !== []) {
    fwrite(STDERR, "Diese Optionen kennt der Abzug nicht — eintragen in SeedImage::OPTIONS_IN oder OPTIONS_OUT:\n");

    foreach ($unbekannt as $name) {
        fwrite(STDERR, "  {$name}\n");
    }

    exit(1);
}

// ─── 4 · Hinschreiben ─────────────────────────────────────────────────────────

$gestalt = SeedImage::shapeOf($tabellen);

$abzug = [
    'erzeugt_von'    => 'scripts/dev/saat-export.php',
    'schemafassung'  => Schema::VERSION,
    'abzugsfassung'  => SeedImage::VERSION,
    'zaehlung'       => $gestalt['zaehlung'],
    'pruefsumme'     => $gestalt['pruefsumme'],
    'optionen'       => $optionen,
    'tabellen'       => $tabellen,
];

$datei = SeedImage::file();

if (! is_dir(dirname($datei)) && ! mkdir($verzeichnis = dirname($datei), 0777, true) && ! is_dir($verzeichnis)) {
    fwrite(STDERR, "Kann {$verzeichnis} nicht anlegen.\n");

    exit(1);
}

// ⚠️ *Überschreiben und nicht löschen-und-neu-anlegen — siehe `AGENTS.md`, 2026-08-25.*
$geschrieben = file_put_contents(
    $datei,
    json_encode($abzug, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);

if ($geschrieben === false) {
    fwrite(STDERR, "Kann {$datei} nicht schreiben.\n");

    exit(1);
}

echo "Abzug: {$datei}\n";

foreach ($gestalt['zaehlung'] as $tabelle => $anzahl) {
    echo '  ' . str_pad($tabelle, 18) . $anzahl . "\n";
}

echo '  ' . str_pad('Optionen', 18) . count($optionen) . "\n";
echo '  ' . str_pad('Prüfsumme', 18) . $gestalt['pruefsumme'] . "\n";
