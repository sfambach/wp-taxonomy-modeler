<?php declare(strict_types=1);
/**
 * Renderer, Konverter und Validatoren als Knoten unter `Settings`.
 *
 *     php scripts/dev/rendering-scaffold-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-511](../../docs/NewConcept/90-decision-log.md) liess sie unter `Constants` entstehen** — *kein
 * neuer Zweig, weil ein Renderer-Zweig `relationKind()`, `storage()` und `holdsData()` in jeder
 * Eigenschaft genau wie `Constants` beantwortet hätte.*
 *
 * ⚠️ **Der Eigentümer hat sie danach in den Ast `Settings` gelegt, zu `Converter` und `Validator`**
 * — *«Renderer und Converter hatten wir in den Settings abgelegt»*, und dort sollen sie sein. Die
 * Saat suchte sie danach noch unter `Constants` und legte am 2026-08-31 **24 Knoten doppelt** an;
 * beides ist berichtigt. *D-511s Begründung bleibt gültig — sie sagte, dass es **kein eigener
 * Zweig** wird, nicht, unter welchem Knoten sie hängen.*
 *
 * ⚠️ **Die eine Zusage, die diese Prüfung wirklich trägt, ist die dritte:** *was der Code kennt, liegt
 * als Knoten im Modell, und was als Knoten liegt, kennt der Code. **Eine Saat mit eigener Namensliste
 * läuft auseinander, ohne dass etwas rot wird** — ein neuer Renderer im Code, kein Knoten im Modell,
 * und die Auswahl zeigt ihn nie. Genau dagegen ist `namesForNodes()` gebaut, und genau das misst
 * Abschnitt 3.*
 *
 * ⚠️ **Sie schreibt in die Datenbank**: die Behälter, die Blätter und ihre Optionen — das ist der
 * Auftrag. *Und einen Schmierknoten, den sie über die **Id ihres eigenen Laufs** wieder wegräumt,
 * samt `register_shutdown_function`, damit auch ein Absturz aufräumt. Das ist die Lehre aus
 * [D-512](../../docs/NewConcept/90-decision-log.md): eine Prüfung, die ihren Müll über den **Namen**
 * sucht, findet ihn nicht mehr, sobald sie ihn umbenannt hat.*
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

use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Validator\ShippedValidators;
use Taxmod\WordPress\Persistence\RenderingScaffold;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

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
$editor    = new ModelEditor($nodes, $relations, $framework, $log);

$renderers  = ShippedRenderers::registry();
$converters = ShippedConverters::registry();
$validators = ShippedValidators::registry();

$scaffold = new RenderingScaffold($editor, $framework, $renderers, $converters, $validators);

// ── Was vor dem ersten Aufruf dastand, damit ein roter Lauf es zurückgeben kann ────────────
//
// ⚠️ **Eine Prüfung, die eine *Saat* aufruft, schreibt, was der Bau sagt — und in einer
// Gegenprüfung ist der Bau absichtlich kaputt.** *Gemessen am 2026-08-29: die Gegenprüfung «die
// Oberflächen-Ausnahme fällt» liess **9 Knoten** unter `Renderer` stehen, jedes Mal neue. Das ist
// [Zeile 28](../../docs/NewConcept/97-implementation-plan.md) der Arbeitsliste von der Schreibseite:
// meine eigenen Prüfungen sind der Grund, dass 720 Setting-Zeilen einen Besitzer hatten, den es
// nicht mehr gab.*
//
// ⚠️ **Die Regel, die daraus folgt: ein Lauf, der fehlschlägt, lässt das Modell, wie er es fand.**
// *Ein grüner Lauf darf säen — das ist der Auftrag. Ein roter hat kein Recht dazu, weil niemand
// weiss, wonach er gesät hat.*
$vorher = [];

foreach (RenderingScaffold::CONTAINERS as $name) {
    $id = (int) get_option(RenderingScaffold::optionForContainer($name), 0);

    $vorher[$name] = ['id' => $id, 'kinder' => []];

    if ($id > 0) {
        foreach ($editor->childrenOf($id) as $child) {
            $vorher[$name]['kinder'][$child->id] = true;
        }
    }
}

// ── Alles, was dieser Lauf hinterlässt, wird an genau einer Stelle zurückgenommen ──────────
//
// ⚠️ **Eine Abschaltfunktion, nicht zwei.** *Mein erster Entwurf hatte je eine für die
// Schmierknoten und für die Rücknahme — und sie **blockierten sich**: die Rücknahme weigert sich,
// solange etwas im Müll liegt, und der Schmierknoten lag im Müll. Zwei Aufräumungen über dasselbe
// Modell sind kein doppelter Schutz, sondern eine Reihenfolgenfalle.*
//
// ⚠️ *`register_shutdown_function` und nicht ein `finally`, damit auch ein Absturz aufräumt — die
// Lehre aus [D-512](../../docs/NewConcept/90-decision-log.md), wo sechs Leichen einer abgestürzten
// Prüfung drei Tage lang im Modell lagen.*

/** @var list<int> Was Abschnitt 7 anlegt, gemerkt über die Id und nicht über den Namen. */
$schmier = [];

/** @var array<string,int> Optionen, die ein Abschnitt verbogen hat, mit ihrem echten Wert. */
$verbogen = [];

register_shutdown_function(static function () use ($editor, $framework, &$vorher, &$schmier, &$verbogen, &$bad): void {
    global $wpdb;

    foreach ($verbogen as $option => $echt) {
        update_option($option, $echt, true);
    }

    $wegzuraeumen = $schmier;

    // ⚠️ **Ein Lauf, der fehlschlägt, lässt das Modell, wie er es fand.** *Gemessen am 2026-08-29:
    // die Gegenprüfung «die Oberflächen-Ausnahme fällt» liess **9 Knoten** unter `Renderer` stehen,
    // bei jedem Lauf neue. Eine Prüfung, die eine **Saat** aufruft, schreibt, was der Bau sagt — und
    // in einer Gegenprüfung ist der Bau absichtlich kaputt. Das ist
    // [Zeile 28](../../docs/NewConcept/97-implementation-plan.md) von der Schreibseite: meine eigenen
    // Prüfungen sind der Grund, dass 720 Setting-Zeilen einen Besitzer hatten, den es nicht gab.*
    //
    // ⚠️ *Ein **grüner** Lauf darf säen — das ist der Auftrag. Ein roter hat kein Recht dazu, weil
    // niemand weiss, wonach er gesät hat.*
    if ($bad > 0) {
        foreach ($vorher as $name => $stand) {
            $id = (int) get_option(RenderingScaffold::optionForContainer($name), 0);

            if ($id <= 0 || $stand['id'] <= 0 || $id !== $stand['id']) {
                continue;
            }

            foreach ($editor->childrenOf($id) as $child) {
                if (! isset($stand['kinder'][$child->id])) {
                    $wegzuraeumen[] = $child->id;
                }
            }
        }
    }

    if ($wegzuraeumen === []) {
        return;
    }

    // ⚠️ **Der Müll muss leer sein, sonst wird nichts gelöscht.** *`clearTrash()` nimmt alles mit,
    // was dort liegt, und das kann dem Eigentümer gehören.*
    //
    // ⚠️ *Die Pfadform ist `1.<trash>.…` mit **Punkten**. **Mein erster Versuch fragte
    // `LIKE '/2/%'` und meldete darum «der Müll ist leer», während fünf Knoten darin lagen** — und
    // `$wpdb` gibt bei einer kaputten Abfrage dasselbe zurück wie bei einem leeren Ergebnis.*
    //
    // ⚠️ *Die eigenen Schmierknoten liegen selbst im Müll und werden abgezogen — **über ihre Ids**.
    // Ohne das blockierte der Schmierknoten aus Abschnitt 7 die Aufräumung, die ihn wegräumen soll.*
    $trash  = $framework->trash()->id;
    $eigene = implode(',', array_map('intval', $wegzuraeumen)) ?: '0';

    // ⚠️ *Seit Fassung 35 fragt der Speicher, was im Muell liegt — der Weg ist keine Spalte mehr
    // (TASK-001). **Damit faellt zugleich die feste `1.` aus dem Muster**, die den Fehler von damals
    // erst moeglich gemacht hat.*
    $imMuell = array_values(array_diff(
        (new \Taxmod\WordPress\Persistence\WpdbNodeRepository())->subtreeIds($trash),
        [$trash],
        array_map('intval', $wegzuraeumen)
    ));

    $drin = count($imMuell);

    if ($drin > 0) {
        echo "\n  ?? " . count($wegzuraeumen) . ' Knoten bleiben stehen: im Müll liegen schon '
            . "{$drin} Knoten, und `clearTrash()` würde die mitnehmen.\n"
            . '     Ids: ' . implode(', ', $wegzuraeumen) . "\n";

        return;
    }

    // ⚠️ *Über `moveToTrash()` und `clearTrash()`, nicht über rohes SQL: `createNode()` legt
    // Einstellungen an — im Journal steht `setting persistent set` für den Behälter `Validator` —
    // und ein rohes `DELETE` auf `nodes` liesse sie besitzerlos zurück. **Genau der Schaden, den
    // diese Aufräumung verhindern soll.***
    foreach ($wegzuraeumen as $id) {
        if ($editor->find($id) !== null) {
            $editor->moveToTrash($id);
        }
    }

    $weg = $editor->clearTrash();

    echo "\n  Zurückgenommen" . ($bad > 0 ? ', weil der Lauf rot war' : '') . ': '
        . json_encode($weg) . "\n";
});

// ⚠️ **Hier stand eine Absage, und sie ist mit ihrem Grund weggefallen.** *Sie hielt den Lauf an,
// solange ein Behälter nicht unter `Constants` lag — weil `import()` ihn dort suchte und bei
// Nichtfinden neu anlegte. **Beides war falsch:** der Eigentümer hatte den Ort zweimal genannt
// («Renderer und Converter hatten wir in den Settings abgelegt», «der Validatorknoten liegt sehr
// wohl in Settings, und da soll er auch sein»), und ich hatte daraus eine offene Frage gemacht
// statt einer Zeile Code. Jetzt sät `import()` in den Ast `Settings`, und die gemerkte Id gilt,
// wo der Knoten auch liegt — nur nicht im Müll.*

// ⚠️ *`import()` und nicht `importOnce()` — die Prüfung soll auch dann etwas messen, wenn die
// Fassung längst gesetzt ist. Zweimal laufen darf nichts anlegen; genau das ist Abschnitt 5.*
$created = $scaffold->import();

echo "\n== 1. Die drei Behälter hängen unter Settings, und ihre Ids sind notiert ==\n";

// ⚠️ *Der Ast `Settings` — sein Ort, zweimal genannt. Hier stand `Constants`.*
$heimat = $framework->rootOf(Branch::Settings);

/** @var array<int, \Taxmod\Core\Model\Node> $unterHeimat */
$unterHeimat = [];

foreach ($editor->childrenOf($heimat->id) as $child) {
    $unterHeimat[$child->id] = $child;
}

/** @var array<string, \Taxmod\Core\Model\Node> $behaelter */
$behaelter = [];

foreach (RenderingScaffold::CONTAINERS as $name) {
    $id = (int) get_option(RenderingScaffold::optionForContainer($name), 0);

    check(
        $name . ' → ' . RenderingScaffold::optionForContainer($name),
        $id > 0 && isset($unterHeimat[$id]) && $unterHeimat[$id]->name === $name,
        $id === 0 ? 'keine Option' : "Id $id ist kein Kind von Settings mit diesem Namen"
    );

    if ($id > 0 && isset($unterHeimat[$id])) {
        $behaelter[$name] = $unterHeimat[$id];
    }
}

echo "\n== 2. Jeder Name des Codes liegt als Knoten, über seine Klasse gefunden ==\n";

// ⚠️ **Umgeschrieben am 2026-09-05 auf die Spalte** (TASK-009, `PR-9`). *Hier stand
// `get_option(RenderingScaffold::optionFor(…))` — die Option, die diese Aufgabe abschafft. **Ein
// Wächter, der nach der alten Form fragt, wird nicht entschärft, sondern auf die neue umgeschrieben**;
// die Zusage bleibt dieselbe: jeder Name des Codes liegt als Knoten unter seinem Behälter, gefunden
// über eine Angabe im Modell und nicht über seinen Namen ([D-022](../../docs/NewConcept/90-decision-log.md)).*
$klasseVon = [
    'Renderer'  => static fn (string $n): ?string => $renderers->classFor($n),
    'Converter' => static fn (string $n): ?string => $converters->classFor($n),
    'Validator' => static fn (string $n): ?string => $validators->classFor($n),
];

$erwartet = [
    'Renderer'  => $renderers->namesForNodes(),
    'Converter' => $converters->namesForNodes(),
    // ⚠️ *Seit dem 2026-08-31 sind es zwei — `range` und `shape`. Aus derselben Naht wie die anderen
    // beiden gelesen und nicht hier aufgezählt.*
    'Validator' => $validators->namesForNodes(),
];

foreach ($erwartet as $behaelterName => $namen) {
    if (! isset($behaelter[$behaelterName])) {
        check($behaelterName . ': Behälter fehlt, Blätter ungeprüft', false);

        continue;
    }

    // ⚠️ **Durch Gruppierungsknoten hindurch, nicht nur die direkten Kinder.** *Der Eigentümer hat
    // Renderer unter `render with label` zusammengefasst — `form` liegt darum als **Enkel** unter
    // `Renderer`. Er hat mich darauf hingewiesen: «weil du den Gruppierungsknoten von Renderer und
    // Converter irgendwie nicht berücksichtigt hattest». **Und es ist dieselbe Regel, die die Auswahl
    // schon hat** ([D-544](../../docs/NewConcept/90-decision-log.md)): sie schaut durch markierte
    // Gruppierungsknoten hindurch. Eine Prüfung, die flache Kinder erwartet, verbietet ihm das
    // Gruppieren — und das hat niemand entschieden.*
    $kinder = [];

    foreach ($nodes->subtreeOf($behaelter[$behaelterName]) as $child) {
        $kinder[$child->id] = $child;
    }

    $fehlend = [];

    foreach ($namen as $name) {
        $klasse = $klasseVon[$behaelterName]($name);
        $node   = $klasse === null ? null : $editor->nodeImplementing($klasse);

        if ($node === null || ! isset($kinder[$node->id])) {
            $fehlend[] = $name . ($klasse === null
                ? ' (keine Klasse registriert)'
                : ($node === null ? ' (kein Knoten nennt ' . $klasse . ')' : " (Id {$node->id} liegt woanders)"));
        }
    }

    check(
        $behaelterName . ': alle ' . count($namen) . ' Namen liegen als Knoten',
        $fehlend === [],
        implode(', ', $fehlend)
    );
}

echo "\n== 3. Und umgekehrt — kein Knoten, den der Code nicht kennt ==\n";

// ⚠️ **Das ist die Hälfte, die eine Namensliste in der Saat nicht hätte.** *Ein gelöschter Renderer
// im Code liesse seinen Knoten stehen, und die Auswahl böte etwas an, das nichts zeichnet.*
foreach ($erwartet as $behaelterName => $namen) {
    if (! isset($behaelter[$behaelterName])) {
        continue;
    }

    // ⚠️ **Ein Gruppierungsknoten ist kein Codename, und das ist kein Mangel.** *`render with label`
    // fasst Renderer zusammen; er zeichnet selbst nichts und hat darum keine Entsprechung im Code.
    // **Erkannt daran, dass er Kinder hat** — nicht an seinem Namen, denn ein Name ist Modellinhalt
    // und darf sich ändern ([D-022](../../docs/NewConcept/90-decision-log.md)).*
    $ueberzaehlig = [];

    foreach ($nodes->subtreeOf($behaelter[$behaelterName]) as $child) {
        if (in_array($child->name, $namen, true)) {
            continue;
        }

        if ($editor->childrenOf($child->id) !== []) {
            continue;
        }

        $ueberzaehlig[] = $child->name . ' (' . $child->id . ')';
    }

    check(
        $behaelterName . ': kein Knoten ohne Entsprechung im Code',
        $ueberzaehlig === [],
        implode(', ', $ueberzaehlig)
    );
}

echo "\n== 4. Die Oberflächen-Renderer bleiben draussen ==\n";

// ⚠️ *Auf sein Wort: «es geht hier nur um die Knotenrenderer». `tree`, `head`, `settings` und die
// anderen sechs bedienen einen Schirm und sind nichts, was jemand für einen Wert wählt.*
$knotenNamen = $renderers->namesForNodes();
$drinnen     = [];

foreach (['tree', 'tree-node', 'labels', 'chooser-node', 'record', 'head', 'settings', 'choice', 'field-row'] as $flaeche) {
    if (in_array($flaeche, $knotenNamen, true)) {
        $drinnen[] = $flaeche;
    }
}

check('keiner der 9 Oberflächen-Renderer wird gesät', $drinnen === [], implode(', ', $drinnen));

// ⚠️ *Und der Rückfall ist **drin** — «hier zeichnet noch nichts» ist eine Wahl, die jemand treffen
// können muss (R14b). Meine erste Zählung liess ihn weg und ergab 15 statt 16.*
check('der Rückfall `plain` wird gesät', in_array('plain', $knotenNamen, true));

echo "\n== 5. Ein zweiter Lauf legt nichts an ==\n";

$zweiter = $scaffold->import();

check('zweiter Lauf ist leer', $zweiter === [], implode(', ', $zweiter));

// ⚠️ **Abschnitte 6 und 7 sind am 2026-09-05 auf die Spalte umgeschrieben** (TASK-009, `PR-9`).
// *Sie prüften den Notnagel über `taxmod_render_converter_roman_id` — **die Option gibt es nicht
// mehr**, seit der Knoten selbst sagt, welche Klasse ihn umsetzt. Die beiden Zusagen bleiben
// wortgleich, nur ihr Gegenstand ist der neue: **eine verlorene Angabe wird nachgetragen, ohne einen
// zweiten Knoten zu machen**, und **eine Angabe im Müll wird nicht geglaubt**.
echo "\n== 6. Der Notnagel trägt eine verlorene Klassenangabe nach, ohne einen zweiten Knoten zu machen ==\n";

$romanKlasse = $converters->classFor('roman');

/** Wie oft `roman` unter `Converter` liegt — die eigentliche Frage beider Abschnitte. */
$romanZaehlen = static function () use ($editor, $behaelter): int {
    $wieviele = 0;

    foreach ($editor->childrenOf($behaelter['Converter']->id) as $child) {
        if ($child->name === 'roman') {
            $wieviele++;
        }
    }

    return $wieviele;
};

if (isset($behaelter['Converter']) && $romanKlasse !== null) {
    $echt = $editor->nodeImplementing($romanKlasse);

    if ($echt === null) {
        check('roman nennt seine Klasse', false, 'kein Knoten nennt ' . $romanKlasse);
    } else {
        $editor->setImplementedBy($echt->id, null);

        $scaffold->import();

        $wieder = $editor->nodeImplementing($romanKlasse);

        check(
            'die Angabe ist wieder da und steht an demselben Knoten',
            $wieder !== null && $wieder->id === $echt->id,
            $echt->id . ' erwartet, ' . ($wieder?->id ?? 0) . ' gefunden'
        );

        check('und `roman` liegt weiterhin genau einmal', $romanZaehlen() === 1, (string) $romanZaehlen());
    }
}

echo "\n== 7. Eine Angabe an einem Knoten im Müll wird nicht geglaubt ==\n";

// ⚠️ **Das ist der Fall, den [D-119](../../docs/NewConcept/90-decision-log.md) erzwingt**: eine Saat
// ist danach gewöhnlicher Inhalt, also kann jemand einen Knoten in den Müll ziehen. *Ohne diese
// Prüfung meldete die Saat ihn als «vorhanden», und die Auswahl zeigte auf etwas, das dort nicht
// mehr hängt.*
//
// ⚠️ **Der Mülleimer selbst statt eines Schmierknotens** — *er ist ein echter, lebender Knoten und
// ganz sicher kein Konverter. **Ein Knoten, den man nicht anlegt, ist der einzige, den man nicht
// wieder loswerden muss** (TASK-039, TASK-047): angefasst wird nur seine Klassenangabe, und die
// wird am Ende dieses Abschnitts zurückgenommen.*
if (isset($behaelter['Converter']) && $romanKlasse !== null) {
    $echt  = $editor->nodeImplementing($romanKlasse);
    $muell = $framework->trash();

    if ($echt !== null) {
        $editor->setImplementedBy($echt->id, null);
        $editor->setImplementedBy($muell->id, $romanKlasse);

        $scaffold->import();

        // ⚠️ **Am Knoten gefragt und nicht über {@see ModelEditor::nodeImplementing()}, und der
        // Unterschied ist der Befund selbst:** *solange die Angabe **auch** im Müll steht, nennen
        // zwei Knoten dieselbe Klasse, und der Nachschlag antwortet mit dem kleineren — dem
        // Mülleimer, den er zu Recht verschweigt. **Was hier zu prüfen ist, ist der echte Knoten:
        // hat er seine Angabe zurückbekommen?** Dass zwei sie gleichzeitig tragen, meldet
        // [`implemented-by-check.php`](implemented-by-check.php); dieser Lauf räumt sie gleich
        // wieder weg.*
        $wieder = $editor->find($echt->id);

        check(
            'die Angabe steht wieder am echten Knoten',
            $wieder !== null && $wieder->implementedBy === $romanKlasse,
            $romanKlasse . ' erwartet, ' . ($wieder?->implementedBy ?? 'nichts') . ' gefunden'
        );

        check('und es wurde kein zweiter `roman` angelegt', $romanZaehlen() === 1, (string) $romanZaehlen());

        // ⚠️ *Zurückgenommen, auch wenn oben etwas rot war — dieser Lauf lässt das Modell, wie er
        // es fand.*
        $editor->setImplementedBy($muell->id, null);
        $editor->setImplementedBy($echt->id, $romanKlasse);
    }
}

echo "\n" . ($created === [] ? "Nichts neu angelegt.\n" : 'Angelegt: ' . implode(', ', $created) . "\n");
echo ($bad === 0 ? "Alles grün: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
