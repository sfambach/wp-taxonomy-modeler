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

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Addon\ShippedAddons;
use Taxmod\WordPress\Persistence\BaseScaffold;
use Taxmod\WordPress\Persistence\CompositionScaffold;
use Taxmod\WordPress\Persistence\Query;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeedImage;
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
// ⚠️ **Ein `SAVEPOINT` und kein zweites `START TRANSACTION`** — *seit die Klammer aus
// `lib/no-write.php` den ganzen Lauf umfasst. Ein zweites `START TRANSACTION` **bestätigt** in MySQL
// stillschweigend alles Bisherige und wäre damit das Gegenteil dessen, was hier stehen soll.*
$wpdb->query('SAVEPOINT vor_der_zweiten_saat');

$zurueckdrehen = static function () use ($wpdb): void {
    $wpdb->query('ROLLBACK TO SAVEPOINT vor_der_zweiten_saat');
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

// ⚠️ **Das ist ein Bericht und keine Zusage — seit dem 2026-09-06, und der Grund ist ein
// Beschluss.** *[D-119](../../docs/NewConcept/90-decision-log.md): «A model with no use for
// `Backrezept` may throw it away, and reactivating the plugin must not bring it back.» **Der
// Eigentümer darf einen gesäten Knoten umbenennen oder wegwerfen** — er hat `Adresse` in `Address`
// umbenannt —, und die Saat sucht am **Namen**. Sie meldet dann «neu angelegt», und das ist keine
// Störung der Saat, sondern die Folge seiner Freiheit.*
//
// ⚠️ **Die Zusage steht eine Zeile tiefer: es darf nichts davon *bleiben*.** *Genau das misst die
// Zählung nach dem Zurückdrehen. Was hier gemeldet wird, ist die Liste der Namen, die es unter
// diesem Namen nicht mehr gibt — nützlich zu wissen, kein Fehler.*
if ($angelegt !== []) {
    foreach ($angelegt as $name => $neu) {
        echo "  hinweis  {$name}: " . implode(', ', array_slice($neu, 0, 8))
            . ' — unter diesem Namen nicht (mehr) im Modell' . "\n";
    }
}

echo "\n2 · Zurückdrehen und nachher zählen\n";

// ⚠️ **Erst zurückdrehen, dann zählen** — *sonst misst die Zählung den Stand **innerhalb** der
// Umklammerung und sagt «1 dazugekommen» über etwas, das gleich wieder verschwindet. Die Zusage
// dieses Laufs ist «es bleibt nichts liegen», und gemessen wird sie nach dem Zurückdrehen.*
$zurueckgedreht = true;

$wpdb->query('ROLLBACK TO SAVEPOINT vor_der_zweiten_saat');

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

// ─────────────────────────────────────────────────────────────────────────────

echo "\n5 · Aus dem Abzug entsteht derselbe Baum\n";

/*
 * ⚠️ **Der Vollzug von [D-600](../../docs/NewConcept/90-decision-log.md)**, hier gemessen: *«eine
 * Neuinstallation entsteht künftig aus einem Abbild des gewachsenen Baums».* Sein Auftrag am
 * 2026-09-06 zu `INF-063`: *«wir sollten den aktuellen bestand einfrieren lass aber alles mit `__`
 * weg das ist dir».*
 *
 * ⚠️ **Die Zusage spricht in Zahlen und einer Prüfsumme, nicht in Namen** — *ein Wächter, der
 * `Adresse` sucht, ist genau der Fehler, den dieser Lauf dreimal in seinen Bestand gesät hat. Der
 * Abzug bringt Nummern mit; geprüft wird, dass dieselben Nummern mit denselben Feldern ankommen.*
 *
 * ⚠️ **Er leert das Modell und spielt den Abzug ein — im `SAVEPOINT` von oben, der danach noch
 * einmal zurückgedreht wird.** *Und über {@see SeedImage::import()} statt `importOnce()`: das
 * `ALTER TABLE … AUTO_INCREMENT` aus `sealIdSpace()` würde in MySQL stillschweigend bestätigen,
 * was die Klammer zurückdrehen soll.*
 */
$abzug = SeedImage::read();

check('der Abzug liegt da', $abzug !== null, SeedImage::file());

if ($abzug !== null) {
    /** @var array{zaehlung: array<string, int>, pruefsumme: string} $ausDerDatei */
    $ausDerDatei = SeedImage::shapeOf($abzug['tabellen']);

    check(
        'der Abzug beschreibt sich selbst richtig',
        $ausDerDatei['pruefsumme'] === ($abzug['pruefsumme'] ?? ''),
        'gerechnet ' . substr($ausDerDatei['pruefsumme'], 0, 12) . ', notiert ' . substr((string) ($abzug['pruefsumme'] ?? ''), 0, 12)
    );

    // ⚠️ *Sein Wort «lass aber alles mit `__` weg» — als Messung und nicht als Zusicherung des
    // Abziehers: die Namen stehen in `label_texts`, und dort wird nachgesehen.*
    $wiesen = [];

    foreach ($abzug['tabellen']['label_texts'] ?? [] as $zeile) {
        if (str_starts_with((string) ($zeile['text_name'] ?? ''), '__')) {
            $wiesen[] = (string) $zeile['text_name'];
        }
    }

    check('keine Wiese mit __ im Abzug', $wiesen === [], implode(', ', array_slice($wiesen, 0, 8)));

    // ⚠️ *Die Einstellungstabellen gehören nicht zum Abzug — das Einheitengerüst schreibt sie (Schritt 7) —, aber ihre
    // Fremdschlüssel halten die Knoten fest: also zuerst sie, dann der Abzug.*
    foreach (['settings_value', 'settings_object'] as $tabelle) {
        $wpdb->query('DELETE FROM ' . Schema::table($tabelle));
    }

    foreach (array_reverse(array_keys(SeedImage::TABLES)) as $tabelle) {
        $wpdb->query('DELETE FROM ' . Schema::table($tabelle));
    }

    check('das Modell ist für den Versuch leer', zaehle('nodes') === 0 && zaehle('relations') === 0);

    $geschrieben = (new SeedImage())->import();

    $imModell = SeedImage::shapeOfModel();

    foreach ($ausDerDatei['zaehlung'] as $tabelle => $sollen) {
        check(
            "{$tabelle}: {$sollen} im Abzug, ebenso viele im Modell",
            ($imModell['zaehlung'][$tabelle] ?? -1) === $sollen,
            'angekommen: ' . ($imModell['zaehlung'][$tabelle] ?? 'nichts') . ', geschrieben: ' . ($geschrieben[$tabelle] ?? 0)
        );
    }

    check(
        'dieselbe Prüfsumme, Zeile für Zeile und Feld für Feld',
        $imModell['pruefsumme'] === $ausDerDatei['pruefsumme'],
        'Modell ' . substr($imModell['pruefsumme'], 0, 12) . ', Abzug ' . substr($ausDerDatei['pruefsumme'], 0, 12)
    );

    $wpdb->query('ROLLBACK TO SAVEPOINT vor_der_zweiten_saat');

    check('sein Bestand steht wieder', zaehle('nodes') === $vorherKnoten && zaehle('relations') === $vorherKanten);
}

echo "\n$ok OK, $bad FAIL\n";

exit($bad === 0 ? 0 : 1);
