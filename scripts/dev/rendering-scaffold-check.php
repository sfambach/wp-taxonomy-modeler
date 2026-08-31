<?php declare(strict_types=1);
/**
 * Renderer, Konverter und Validatoren als Knoten unter `Constants`.
 *
 *     php scripts/dev/rendering-scaffold-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-511](../../docs/NewConcept/90-decision-log.md):** *sie werden Knoten unter `Constants` —
 * kein neuer Zweig, weil ein Renderer-Zweig `relationKind()`, `storage()` und `holdsData()` in jeder
 * Eigenschaft genau wie `Constants` beantwortet hätte.*
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
use Taxmod\WordPress\Persistence\RenderingScaffold;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
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
$edges     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, new TableIdentityAllocator(), $log);
$editor    = new ModelEditor($nodes, $edges, new TableIdentityAllocator(), $framework, $log);

$renderers  = ShippedRenderers::registry();
$converters = ShippedConverters::registry();

$scaffold = new RenderingScaffold($editor, $framework, $renderers, $converters);

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

    $drin = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}taxmod_nodes
         WHERE path LIKE %s AND id NOT IN ({$eigene})",
        $wpdb->esc_like('1.' . $trash . '.') . '%'
    ));

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

// ⚠️ **Diese Prüfung hat sein Modell beschädigt, und deshalb steht hier zuerst eine Absage.**
//
// ⚠️ *Gemessen am 2026-08-31: der Eigentümer hatte `Renderer` aus `Constants` heraus in den Ast
// `Settings` verschoben — auf sein Wort «die Renderer von label with roles zu render with label
// schieben». `import()` prüft die gemerkte Id **gegen den Elternknoten** `Constants`, fand sie dort
// nicht mehr, und legte **24 Knoten** ein zweites Mal an: einen kompletten leeren `Renderer`-Baum,
// `Converter` und `Validator` dazu. **Die gemerkten Ids zeigten danach auf die leeren.***
//
// ⚠️ **Nichts sah kaputt aus, und das ist das Schlimme daran.** *Die Verweise in den Datensätzen zeigten
// weiter auf den echten Baum; die Prüfung lief grün. Aufgefallen ist es nur, weil `page-blocks-check`
// plötzlich einen **zweiten** Knoten namens `form` fand.*
//
// ⚠️ **Wer nachgeben muss, ist nicht entschieden** — folgt die Saat einem verschobenen Knoten (gemerkte
// Id gewinnt über den Elternknoten), oder ist das Verschieben eines gesäten Knotens aus seinem Ast heraus
// zu verweigern? *Solange das offen ist, sät diese Prüfung nicht. **Eine Prüfung, die eine zweite Heimat
// für eine Sache anlegt, ist schlimmer als eine, die nicht läuft.***
$constantsRoot = $framework->rootOf(Branch::Constants);
$anderswo      = [];

foreach (RenderingScaffold::CONTAINERS as $behaelterName) {
    $ids = $wpdb->get_col($wpdb->prepare(
        'SELECT id FROM ' . Schema::table('nodes') . ' WHERE BINARY name = %s',
        $behaelterName
    ));

    // ⚠️ *`null` heisst «Abfrage kaputt» und nicht «nichts gefunden» — und eine Absage, die auf einer
    // kaputten Abfrage «alles in Ordnung» sagt, wäre genau die Sorte Wächter, die nichts wiegt.*
    if ($ids === null) {
        fwrite(STDERR, 'Abfrage kaputt: ' . $wpdb->last_error . "\n");
        exit(2);
    }

    foreach ($ids as $rohId) {
        $einer = $editor->find((int) $rohId);

        if ($einer !== null && $einer->parentId() !== $constantsRoot->id) {
            $anderswo[] = $behaelterName . ' #' . $einer->id;
        }
    }
}

if ($anderswo !== []) {
    echo "\n== Abgesagt ==\n";
    echo '  Diese Behaelter liegen nicht unter «Constants»: ', implode(', ', $anderswo), "\n";
    echo "  Ein import() wuerde sie ein zweites Mal anlegen. Siehe Arbeitsliste.\n";
    echo "  scripts/dev/undo-duplicate-constants.php raeumt eine schon entstandene Doppelung weg.\n";

    exit(0);
}

// ⚠️ *`import()` und nicht `importOnce()` — die Prüfung soll auch dann etwas messen, wenn die
// Fassung längst gesetzt ist. Zweimal laufen darf nichts anlegen; genau das ist Abschnitt 5.*
$created = $scaffold->import();

echo "\n== 1. Die drei Behälter hängen unter Constants, und ihre Ids sind notiert ==\n";

$constants = $framework->rootOf(Branch::Constants);

/** @var array<int, \Taxmod\Core\Model\Node> $unterConstants */
$unterConstants = [];

foreach ($editor->childrenOf($constants->id) as $child) {
    $unterConstants[$child->id] = $child;
}

/** @var array<string, \Taxmod\Core\Model\Node> $behaelter */
$behaelter = [];

foreach (RenderingScaffold::CONTAINERS as $name) {
    $id = (int) get_option(RenderingScaffold::optionForContainer($name), 0);

    check(
        $name . ' → ' . RenderingScaffold::optionForContainer($name),
        $id > 0 && isset($unterConstants[$id]) && $unterConstants[$id]->name === $name,
        $id === 0 ? 'keine Option' : "Id $id ist kein Kind von Constants mit diesem Namen"
    );

    if ($id > 0 && isset($unterConstants[$id])) {
        $behaelter[$name] = $unterConstants[$id];
    }
}

echo "\n== 2. Jeder Name des Codes liegt als Knoten, über seine Id gefunden ==\n";

$erwartet = [
    'Renderer'  => $renderers->namesForNodes(),
    'Converter' => $converters->namesForNodes(),
    'Validator' => [],
];

foreach ($erwartet as $behaelterName => $namen) {
    if (! isset($behaelter[$behaelterName])) {
        check($behaelterName . ': Behälter fehlt, Blätter ungeprüft', false);

        continue;
    }

    $kinder = [];

    foreach ($editor->childrenOf($behaelter[$behaelterName]->id) as $child) {
        $kinder[$child->id] = $child;
    }

    $fehlend = [];

    foreach ($namen as $name) {
        $id = (int) get_option(RenderingScaffold::optionFor($behaelterName, $name), 0);

        if ($id === 0 || ! isset($kinder[$id]) || $kinder[$id]->name !== $name) {
            $fehlend[] = $name . ($id === 0 ? ' (keine Option)' : " (Id $id)");
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

    $ueberzaehlig = [];

    foreach ($editor->childrenOf($behaelter[$behaelterName]->id) as $child) {
        if (! in_array($child->name, $namen, true)) {
            $ueberzaehlig[] = $child->name . ' (' . $child->id . ')';
        }
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

echo "\n== 6. Der Notnagel trägt eine verlorene Option nach, ohne einen zweiten Knoten zu machen ==\n";

if (isset($behaelter['Converter'])) {
    $option = RenderingScaffold::optionFor('Converter', 'roman');
    // ⚠️ *`$alteId` und nicht `$vorher`. **Der erste Entwurf hiess hier auch `$vorher`, und
    // weil die Abschaltfunktion die Momentaufnahme **per Referenz** hält, machte diese eine Zeile
    // sie zu einer Zahl** — die Rücknahme lief in ein `foreach` über einen `int` und liess neun
    // Knoten stehen. *Eine Prüfung, die aufräumen soll, hatte einen Namenskonflikt mit sich
    // selbst.*
    $alteId = (int) get_option($option, 0);

    delete_option($option);

    $scaffold->import();

    $nachher = (int) get_option($option, 0);

    check('die Option ist wieder da und zeigt auf denselben Knoten', $nachher === $alteId, "$alteId → $nachher");

    $romanNodes = 0;

    foreach ($editor->childrenOf($behaelter['Converter']->id) as $child) {
        if ($child->name === 'roman') {
            $romanNodes++;
        }
    }

    check('und `roman` liegt weiterhin genau einmal', $romanNodes === 1, (string) $romanNodes);
}

echo "\n== 7. Eine Id, die nicht mehr unter ihrem Behälter hängt, wird nicht geglaubt ==\n";

// ⚠️ **Das ist der Fall, den [D-119](../../docs/NewConcept/90-decision-log.md) erzwingt**: eine Saat
// ist danach gewöhnlicher Inhalt, also kann jemand einen Knoten in den Müll ziehen. *Ohne diese
// Prüfung meldete die Saat ihn als «vorhanden», und die Auswahl zeigte auf etwas, das dort nicht
// mehr hängt.*
if (isset($behaelter['Converter'])) {
    $option = RenderingScaffold::optionFor('Converter', 'roman');
    $echt   = (int) get_option($option, 0);

    // ⚠️ **Die Id des Mülleimers selbst, statt eines Schmierknotens** — sie ist ein echter,
    // lebender Knoten und ist ganz sicher kein Kind von `Converter`. *Genau das ist die Frage.*
    //
    // ⚠️ **Der erste Entwurf legte hier einen Wegwerfknoten an, und das war falsch herum gedacht.**
    // *Er musste danach weg, weggeräumt wird über `clearTrash()`, und `clearTrash()` nimmt **alles**
    // mit, was im Müll liegt. Gemessen lagen dort vier Knoten aus `journal-address-check` und
    // `path-check` — also verweigerte die Aufräumung den Dienst, richtigerweise, und meine
    // Wegwerfknoten häuften sich statt zu verschwinden.* **Ein Knoten, den man nicht anlegt, ist der
    // einzige, den man nicht wieder loswerden muss.**
    $fremd = $framework->trash()->id;

    $verbogen[$option] = $echt;

    update_option($option, $fremd, true);

    $scaffold->import();

    check(
        'die Option zeigt wieder auf den echten Knoten',
        (int) get_option($option, 0) === $echt,
        $echt . ' erwartet, ' . get_option($option, 0) . ' gefunden'
    );

    $romanNodes = 0;

    foreach ($editor->childrenOf($behaelter['Converter']->id) as $child) {
        if ($child->name === 'roman') {
            $romanNodes++;
        }
    }

    check('und es wurde kein zweiter `roman` angelegt', $romanNodes === 1, (string) $romanNodes);
}

echo "\n" . ($created === [] ? "Nichts neu angelegt.\n" : 'Angelegt: ' . implode(', ', $created) . "\n");
echo ($bad === 0 ? "Alles grün: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
