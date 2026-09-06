<?php declare(strict_types=1);
/**
 * Eine Saat, die zweimal läuft, darf nichts verdoppeln — vorher gezählt, nachher gezählt.
 *
 *     php scripts/dev/seed-twice-check.php [path/to/wordpress]
 *
 * ⚠️ **Der Fall, den es wirklich gab.** *Beim Umbau auf `sort_order` (TASK-012) antwortete die
 * Kinderabfrage **leer statt zu scheitern**. Jedes Gerüst prüft seine eigene Arbeit, indem es die
 * vorhandenen Kinder nach Namen durchsieht — es fand keine und legte alles ein zweites Mal an:
 * **38 Knoten und 54 Kanten Rückstand in einem einzigen Durchlauf**, drei komplette Sätze der
 * Datentypen. **Kein Wächter hat das gefunden.**
 *
 * ```mermaid
 * flowchart LR
 *   S["Saat läuft"] --> F["fragt: gibt es das schon?"]
 *   F --> A["ja → nichts tun"]
 *   F --> B["nein → anlegen"]
 *   B -.->|"Frage kaputt, Antwort leer"| D["legt alles doppelt an"]
 * ```
 *
 * **Die Zusage hier ist die Umkehrung der Frage:** nicht «antwortet die Abfrage richtig» — das ist
 * {@see silent-query-check.php} —, sondern «und wenn nicht, sieht man es». Der Lauf lässt Saat und
 * alle vier Gerüste **ein zweites Mal** über den Bestand gehen, an der `importOnce`-Sperre vorbei,
 * und zählt vorher und nachher. **Jede Zahl, die sich bewegt, ist ein Fehler.**
 *
 * Geprüft wird viererlei:
 *
 * 1. **Knoten und Kanten sind vorher und nachher gleich viele.**
 * 2. **Kein Elternknoten hat zwei gleichnamige Kinder** — der sichtbare Abdruck einer doppelten Saat.
 * 3. **Der zweite Lauf meldet selbst nichts Angelegtes** — die Gerüste geben zurück, was sie taten.
 * 4. **Die gemerkten Rahmenknoten zeigen noch auf dieselben Nummern** — eine zweite Saat hätte die
 *    Optionen auf neue Knoten umgebogen.
 *
 * ⚠️ **Der Lauf schreibt — und dreht am Ende alles zurück.** *Bis zum 2026-09-06 stand hier «er legt
 * nichts an und räumt nichts weg», und gemeint war: **die Saat** legt nichts an. Legte sie doch etwas
 * an, blieb es liegen. **Und sie legte an:** der Eigentümer hatte `Adresse` in `Address` umbenannt,
 * die Saat sucht am Namen, fand keinen — und legte die deutsche `Adresse` jedes Mal neu. Drei Stück
 * in seinem Bestand. Seitdem läuft der zweite Durchgang in einer Umklammerung, die zurückgedreht
 * wird: gezählt und gemeldet wird alles, behalten nichts.*
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

use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Validator\ShippedValidators;
use Taxmod\WordPress\Persistence\BaseScaffold;
use Taxmod\WordPress\Persistence\CompositionScaffold;
use Taxmod\WordPress\Persistence\Query;
use Taxmod\WordPress\Persistence\RenderingScaffold;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\UnitScaffold;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

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

function zaehle(string $tabelle): int
{
    return (int) Query::value("{$tabelle} zählen", 'SELECT COUNT(*) FROM ' . Schema::table($tabelle));
}

/**
 * Elternknoten mit zwei gleichnamigen Kindern — der Abdruck einer doppelten Saat.
 *
 * ⚠️ **Diese Abfrage war blind, und sie war es seit [D-581](../../docs/NewConcept/90-decision-log.md).**
 * *Sie suchte Geschwister über `relations.kind = 'inheritance'` — gemessen am 2026-09-06 gibt es
 * davon **null Zeilen**: die Vererbung liegt seit jenem Beschluss in `nodes.parent_node_id`. Der
 * Wächter hat also drei gleichnamige `Adresse` im Bestand des Eigentümers nicht gemeldet, die er
 * selbst angelegt hatte. **Ein Wächter, der seinen eigenen Rückstand übersieht.***
 */
function doppelteGeschwister(): array
{
    return Query::rows(
        'doppelte Geschwister suchen',
        'SELECT k.parent_node_id AS from_node_id, k.name, COUNT(*) AS wie_oft
         FROM ' . Schema::table('nodes_named') . ' k
         WHERE k.parent_node_id IS NOT NULL
         GROUP BY k.parent_node_id, k.name
         HAVING wie_oft > 1'
    );
}

/** Die Nummern, die sich die Rahmenknoten in den Optionen gemerkt haben. */
function gemerkteRahmenknoten(): array
{
    global $wpdb;

    $zeilen = Query::rows(
        'gemerkte Rahmenknoten lesen',
        $wpdb->prepare(
            'SELECT option_name, option_value FROM ' . $wpdb->options . ' WHERE option_name LIKE %s ORDER BY option_name',
            $wpdb->esc_like('taxmod_') . '%node%'
        )
    );

    $aus = [];

    foreach ($zeilen as $z) {
        $aus[(string) $z['option_name']] = (string) $z['option_value'];
    }

    return $aus;
}

$nodes     = new WpdbNodeRepository();
$relations     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$editor    = new ModelEditor($nodes, $relations, $framework, $log);
$typeNodes = new SeededTypeNodes($nodes, $framework);
$labels    = new Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale(), $log);

// ⚠️ *Dieselbe Reihenfolge wie in `Plugin::activate()` — `unitScaffold` braucht, was `baseScaffold`
// legt, und `compositionScaffold` braucht beide.*
$gerueste = [
    'base'        => new BaseScaffold($editor, $framework, $typeNodes),
    'unit'        => new UnitScaffold($editor, $framework, $labels, $typeNodes),
    'composition' => new CompositionScaffold($editor, $framework, $typeNodes),
    'rendering'   => new RenderingScaffold(
        $editor,
        $framework,
        ShippedRenderers::registry(),
        ShippedConverters::registry(),
        ShippedValidators::registry()
    ),
];

echo "0 · Vorher zählen\n";

$vorherKnoten  = zaehle('nodes');
$vorherKanten  = zaehle('relations');
$vorherOption  = gemerkteRahmenknoten();
$vorherDoppelt = doppelteGeschwister();

echo "  {$vorherKnoten} Knoten, {$vorherKanten} Kanten\n";

check(
    'vorher keine doppelten Geschwister',
    $vorherDoppelt === [],
    count($vorherDoppelt) . ' Gruppen — Rückstand, der schon lag'
);

echo "\n1 · Saat und Gerüste ein zweites Mal\n";

// ⚠️ **Ab hier schreibt der Lauf, und ab hier wird alles wieder zurückgedreht.**
//
// ⚠️ *Der Kopf dieser Datei versprach bis zum 2026-09-06 das Gegenteil — «er legt nichts an und
// räumt nichts weg» — und meinte damit: **die Saat** legt nichts an. Tut sie es doch, blieb es
// liegen. **Genau das ist passiert.** Der Eigentümer hat `Adresse` in `Address` umbenannt und
// anders zusammengesetzt; `CompositionScaffold::ensure()` sucht am **Namen**, fand keinen — und
// legte die deutsche `Adresse` neu an. **Bei jedem Lauf eine.** Gemessen an seinem Bestand:
// **drei** Stück, dazu zehn Knoten aus anderen Prüfläufen.
//
// ⚠️ **Die Zusage bleibt dieselbe, der Preis fällt weg:** geprüft wird weiter der **Lauf** und nicht
// die Sperre, aber innerhalb einer Umklammerung, die am Ende zurückgedreht wird. *Was der zweite
// Lauf anlegt, wird gezählt, gemeldet — und nicht behalten.*
$wpdb->query('START TRANSACTION');

$zurueckdrehen = static function () use ($wpdb): void {
    $wpdb->query('ROLLBACK');
};

register_shutdown_function($zurueckdrehen);

// ⚠️ **An `importOnce()` vorbei und mit Absicht.** *Die Sperre über die Fassungsnummer ist genau das,
// was den Fehler im September **nicht** verhindert hat: ein Schemaschritt hebt die Fassung, und dann
// läuft die Saat wieder — und muss dabei nichts tun. Geprüft wird also der Lauf, nicht die Sperre.*
$framework->seed();

$angelegt = [];

// ⚠️ **Ein Abbruch ist hier ein Befund und kein Absturz.** *Genau so sieht der Fehler von TASK-012
// heute aus: {@see Query} lässt die kaputte Kinderabfrage werfen, statt sie leer antworten zu lassen —
// und das gehört als rote Zeile gemeldet, nicht als Stapelspur.*
try {
    foreach ($gerueste as $name => $geruest) {
        $neu = $geruest->import();

        if ($neu !== []) {
            $angelegt[$name] = $neu;
        }
    }
} catch (\Throwable $fehler) {
    check('der zweite Lauf kommt durch', false, $fehler->getMessage());
}

check(
    'der zweite Lauf meldet nichts Angelegtes',
    $angelegt === [],
    implode('; ', array_map(
        static fn (string $k, array $v): string => $k . ': ' . implode(', ', array_slice($v, 0, 8)),
        array_keys($angelegt),
        $angelegt
    ))
);

echo "\n2 · Nachher zählen\n";

$nachherKnoten = zaehle('nodes');
$nachherKanten = zaehle('relations');

echo "  {$nachherKnoten} Knoten, {$nachherKanten} Kanten\n";

check(
    'gleich viele Knoten',
    $nachherKnoten === $vorherKnoten,
    ($nachherKnoten - $vorherKnoten) . ' dazugekommen'
);

check(
    'gleich viele Kanten',
    $nachherKanten === $vorherKanten,
    ($nachherKanten - $vorherKanten) . ' dazugekommen'
);

echo "\n3 · Keine gleichnamigen Geschwister entstanden\n";

$nachherDoppelt = doppelteGeschwister();

check(
    'keine doppelten Geschwister',
    count($nachherDoppelt) === count($vorherDoppelt),
    implode(', ', array_map(
        static fn (array $z): string => "#{$z['from_node_id']} «{$z['name']}» x{$z['wie_oft']}",
        array_slice($nachherDoppelt, 0, 10)
    ))
);

echo "\n4 · Die gemerkten Rahmenknoten stehen noch\n";

$nachherOption = gemerkteRahmenknoten();
$verschoben    = [];

foreach ($vorherOption as $name => $wert) {
    if (($nachherOption[$name] ?? null) !== $wert) {
        $verschoben[] = $name . ': ' . $wert . ' → ' . ($nachherOption[$name] ?? 'fort');
    }
}

check('keine Option zeigt auf einen neuen Knoten', $verschoben === [], implode(', ', $verschoben));

echo "\n$ok OK, $bad FAIL\n";

exit($bad === 0 ? 0 : 1);
