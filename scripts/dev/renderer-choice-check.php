<?php declare(strict_types=1);
/**
 * Welchen Renderer bekommt ein Knoten — an echten Daten festgenagelt.
 *
 *     php scripts/dev/renderer-choice-check.php [path/to/wordpress]
 *
 * ⚠️ **Diese Datei entstand aus einem Fehler, den nichts gemeldet hätte.** *Am 2026-08-30 wurden die
 * 32 Renderer-Einstellungen an ihre neue Adresse umgezogen — **bevor der Leser dort suchte**. Jeder
 * Knoten hätte seinen Renderer verloren, und **alle 305 Randprüfungen blieben grün**, weil keine
 * einzige die Renderer-Wahl bewacht. `renderer-snapshot.php` sieht danach aus, hat aber null Zusagen:
 * es ist ein Abdruck, keine Prüfung.*
 *
 * ⚠️ **Sie ist die Sicherung für den Umzug, nicht für den Renderer.** *Sie hält fest, was **heute**
 * herauskommt. Wandert die Angabe an eine neue Stelle und der Leser wandert mit, bleibt sie grün;
 * wandert nur eines von beidem, wird sie rot. **Genau das ist ihr Zweck.***
 *
 * ⚠️ *Die Knoten werden über ihren **Namen** gesucht — und **fehlt einer, ist das ein Fehlschlag und
 * kein Überspringen**. Eine Prüfung, die ihren Gegenstand nicht findet, ist nicht grün.*
 *
 * @see docs/NewConcept/30-renderer.md
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
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\Rendering;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\ModelValues;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
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

$nodes     = new WpdbNodeRepository();
$relations     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$registry  = ShippedRenderers::registry();

// ⚠️ **Die Prüfung geht denselben Weg wie die Anwendung, und das ist der Punkt.** *Sie fragt beide
// Quellen und lässt die neue gewinnen — genau wie {@see \Taxmod\Core\Service\Rendering}. **Fragte sie
// nur die alte, würde sie nach dem Umzug rot, obwohl die Oberfläche stimmt** — und wäre damit
// wertlos für das, wofür sie gebaut wurde.*
$model = new ModelValues(new WpdbRecordRepository(), $relations, $nodes, $framework);

/** @return array<string,\Taxmod\Core\Model\ResolvedSetting> */
function beideQuellen(\Taxmod\Core\Model\Node|\Taxmod\Core\Model\Relation $subject): array
{
    global $model;

    // ⚠️ *Es waren einmal **zwei** Quellen — die `settings`-Tabelle und das Modell. Die Tabelle ist
    // mit D-579 gestrichen; geblieben ist die eine, die schon vorher gewann.*
    return $subject instanceof \Taxmod\Core\Model\Node
        ? $model->forNode($subject)
        : $model->forUseSite($subject);
}

/** Der Knoten mit diesem Namen, oder null. */
function knoten(string $name): ?\Taxmod\Core\Model\Node
{
    global $wpdb, $nodes;

    $id = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . Schema::table('nodes_named') . ' WHERE name = %s LIMIT 1',
        $name
    ));

    return $id === 0 ? null : $nodes->find($id);
}

echo "\n== Welchen Renderer ein Knoten bekommt ==\n";

// ⚠️ **Hier standen bis zum 2026-09-01 festgenagelte Renderernamen, und das war der Fehler.**
// *Die Absicht war richtig — «damit ein Umzug ihn nicht unbemerkt verliert» —, aber die Prüfung
// konnte **«ein Umzug hat den Wert verloren»** nicht von **«der Eigentümer hat ihn geändert»**
// unterscheiden. **Beides sah gleich aus.** Am 2026-09-01 stand «Base units» auf `chooser-inline`
// und «Integer» auf `spinner`; gemessen in den Daten waren es `table` und `slider` — er hatte den
// Wähler benutzt, den wir tags zuvor gebaut hatten. **Eine Prüfung, die einen benutzerveränderlichen
// Wert festnagelt, wird rot, sobald jemand die Funktion benutzt.***
//
// ⚠️ **Die Zusage ist jetzt der Mechanismus statt des Werts, und sie ist strenger:** *was gespeichert
// ist, wird auch gezeichnet — **egal was es ist.** Ein Umzug, der die Wahl verliert, fällt weiterhin
// auf: die Auflösung liefert dann den Rückfall statt des gespeicherten Werts. Der gespeicherte Wert
// wird dafür auf einem **zweiten, unabhängigen Weg** gelesen, direkt aus den Datensätzen — zwei Wege
// zur selben Antwort sind der Grund, dass die Zusage etwas wiegt.*
$erwartet = [];

foreach (['Base units', 'Passiv', 'Integer', 'Dimension', 'Prefixes', 'Parts List'] as $name) {
    $k = knoten($name);

    if ($k === null) {
        $erwartet[$name] = null;

        continue;

    }
    // Der gespeicherte Renderer, unabhängig von der Registratur gelesen.
    // ⚠️ **Diese Abfrage ist mit TASK-057 auf die Kante zurueckgezogen**
    // ([D-642](../../docs/NewConcept/90-decision-log.md)): *`Knoten → default-Satz → Wertzeile an
    // der Einstellungskante `renderer` → Satz`, und die `node_id` dieses Satzes ist der Renderer.
    // Der Umweg ueber `nodes.settings_record_id` ist gefallen — er war die Form, die ich aus seinem
    // Satz gemacht hatte, nicht die, die er gemeint hat.*
    $erwartet[$name] = $wpdb->get_var($wpdb->prepare(
        'SELECT ziel.name
           FROM ' . Schema::table('node_records') . ' halter
           JOIN ' . Schema::table('relation_records') . " wert ON wert.node_record_id = halter.id
                AND wert.relation_id = %d AND wert.value_ref_kind = 'record'
           JOIN " . Schema::table('node_records') . ' satz ON satz.id = wert.value_ref
           JOIN ' . Schema::table('nodes_named') . " ziel ON ziel.id = satz.node_id
          WHERE halter.node_id = %d AND halter.record_type = 'default'
          LIMIT 1",
        $framework->settingRelationId(SettingKey::Renderer),
        $k->id
    ));
}

foreach ($erwartet as $name => $soll) {
    $node = knoten($name);

    if ($node === null) {
        check("«{$name}» steht im Modell", false, 'kein Knoten dieses Namens');

        continue;
    }

    $gewaehlt = $registry->chosenFor(
        $node,
        beideQuellen($node),
        Purpose::Edit
    );

    if ($soll === null) {
        // ⚠️ *Nichts gespeichert heisst Rückfall, und der muss sich als solcher zeigen (`R14b`) —
        // nicht: die Prüfung sagt nichts.*
        check("«{$name}» bekommt ohne gespeicherte Wahl einen Rückfall", $gewaehlt !== null, 'nichts');

        continue;
    }

    check(
        "«{$name}» zeichnet mit dem, was gespeichert ist — «{$soll}»",
        ($gewaehlt?->name() ?? null) === $soll,
        'gespeichert «' . $soll . '», gezeichnet «' . ($gewaehlt?->name() ?? 'nichts') . '»'
    );
}

// ⚠️ **Die Gegensicherung, und ohne sie wäre die Lockerung oben ein Loch.** *Die Zusage «zeichnet,
// was gespeichert ist» ist auch dann grün, wenn **nichts** gespeichert ist — dann greift der
// Rückfallzweig. **Ein Umzug, der alle Wahlen verliert, käme damit durch**, und genau davor sollte
// diese Prüfung schützen. Also wird zusätzlich gezählt, **wie viele der sechs überhaupt eine
// gespeicherte Wahl haben.** Gemessen am 2026-09-01: **6 von 6.** Die Zahl darf steigen und nie
// fallen — fällt sie, hat etwas eine Wahl verloren, und es ist gleichgültig welche.*
$mitWahl = count(array_filter($erwartet, static fn ($x): bool => $x !== null));

check(
    'mindestens 6 der geprueften Knoten haben eine gespeicherte Wahl',
    $mitWahl >= 6,
    $mitWahl . ' statt 6 — eine Wahl ist verlorengegangen'
);

echo "\n== Und an einer Verwendungsstelle ==\n";

// ⚠️ **Die vier Angaben an Kanten sind der eigentliche Grund für den zweistufigen Pfad.** *Ohne sie
// hätte man «der Renderer **dieses Feldes**» nicht ausdrücken können, ohne einen Behälter zu erfinden.*
// ⚠️ **Fest hingeschrieben und nicht aus der Tabelle gesucht — der Unterschied ist der ganze Wert.**
// *Mein erster Entwurf holte die Fälle aus `settings`. **Nach dem Umzug steht dort nichts mehr**, die
// Schleife wäre leer, und die Prüfung hätte gemeldet «keine gefunden» statt «der Renderer stimmt» —
// wieder grün beziehungsweise rot aus dem falschen Grund.*
// ⚠️ **Diese vier Fälle sind auf sein Wort entfernt, und die Prüfung sagt jetzt das Gegenteil.**
//
// ⚠️ *Sie standen hier als Beleg, dass ein Renderer **an einer Kante** wirkt. Der Eigentümer hat
// widersprochen, als ich sie «überschrieben» nannte: «du sagst überschrieben, ich sehe aber nichts —
// das ist wahrscheinlich ein alter Datensatz, weil so weit, dass wir an Kanten überschreiben, sind wir
// ja noch nicht.» **Gemessen hatte er recht:** alle 31 Teile entstanden in derselben Sekunde,
// `2026-08-30 13:15:17`, in einem Umzugslauf von mir; die alte `settings`-Tabelle konnte als Besitzer
// auch eine Kante kennen, und `migrate-renderer-settings.php` hat das wörtlich übernommen.*
//
// ⚠️ **Warum das schlimmer war als keine Angabe:** *sie wirkte. `Passiv.Tolerance` zeichnete mit
// `field`, während sein Zielknoten `Integer` `spinner` sagt — eingestellt hatte es niemand. Sein
// Auftrag: «entferne mal die Altlasten.»*
//
// ⚠️ *Also hält diese Zusage jetzt fest, **dass keine da sind** — und mit ihr, dass die zweistufige
// Notation im Bestand unbenutzt ist. Kommt eine Verwendungsstelle wieder, weil jemand sie einstellt,
// wird sie rot und verlangt eine Entscheidung.*
//
// ⚠️ **Die Zusage ist am 2026-09-06 enger gefasst worden, und die Aenderung ist ein sichtbarer Teil
// von [D-659](../../docs/NewConcept/90-decision-log.md)** (`PR-9`). *Sie zaehlte **jeden**
// zweistufigen Pfad und meinte den Renderer. **Seit [D-611](../../docs/NewConcept/90-decision-log.md)
// darf eine Verwendungsstelle eine Einstellung ueberschreiben** — sein Wort: «a, aber aktuell nur
// fuer Settings» —, und `display_size` ist genau der Fall, fuer den das gebaut wurde: `Street Name`
// breit, `House Number` schmal, beide auf `Text` zeigend. **Die alte Fassung waere rot geworden, weil
// eine Entscheidung gebaut wurde**, und haette dabei ausgesehen wie ein Rueckfall.*
//
// ⚠️ *Was sie festhaelt, bleibt unveraendert: **keine Renderer-Wahl an einer Kante**. Genau das waren
// die vier Altlasten, und genau das ist weiter verboten.*
$mitPunkt = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('relation_records') . " WHERE path LIKE '%.%' AND relation_id = %d",
    $framework->settingRelationId(SettingKey::Renderer)
));

check('keine Renderer-Wahl an einer Verwendungsstelle', $mitPunkt === 0, (string) $mitPunkt);

// ⚠️ *Der Gegenfall: es gibt überhaupt Kanten-Datensätze. Sonst wäre «keine zweistufigen» auch dann
// grün, wenn die Tabelle leer wäre.*
$alle = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('relation_records'));

check('und es gibt Kanten-Datensaetze', $alle > 50, (string) $alle);

$stellen = [];

foreach ($stellen as [$vonName, $feldName, $soll]) {
    $von = knoten($vonName);

    if ($von === null) {
        check("«{$vonName}» steht im Modell", false, 'kein Knoten dieses Namens');

        continue;
    }

    $kante = null;

    foreach ($relations->fieldRelationsOf([...$von->ancestorIds(), $von->id]) as $eine) {
        if ($eine->name === $feldName) {
            $kante = $eine;
        }
    }

    if ($kante === null) {
        check("«{$vonName}» hat ein Feld «{$feldName}»", false, 'nicht gefunden');

        continue;
    }

    $gewaehlt = $registry->chosenFor($kante, beideQuellen($kante), Purpose::Edit);

    check(
        "«{$vonName}.{$feldName}» zeichnet mit «{$soll}»",
        ($gewaehlt?->name() ?? null) === $soll,
        $gewaehlt?->name() ?? 'nichts'
    );
}

echo "\n== Und die Vorschau folgt dem, was im Datensatz steht ==\n";

// ⚠️ **Sein Befund:** *«Renderer-Änderung ändert die Preview nicht, selbst nach Speichern.»* *Gemessen
// stimmte es: `Integer` sagte im Modell `slider`, und {@see \Taxmod\Core\Service\Rendering::valueOfType()}
// zeichnete ein Textfeld — den Typvorgabewert. **Sechster Fall derselben Sache an einem Tag:** die
// Angaben sind in die Datensätze gezogen ([D-529](../../docs/NewConcept/90-decision-log.md)), und dieser
// Leser fragte weiter nur die alte Tabelle.*
//
// ⚠️ **Die Erwartung wird aus dem Modell abgeleitet, nicht hingeschrieben.** *Eine Liste «Integer muss
// slider sein» wäre morgen rot, weil er den Renderer ändern darf — und genau das soll er ja. Geprüft
// wird die **Übereinstimmung**: was das Modell sagt, muss man an der Zeichnung wiedererkennen.*
// ⚠️ *Ein eigener Zeichenlauf, weil diese Prüfung bisher nur die Registratur befragte — sie will jetzt
// wissen, was am Ende **auf der Seite** steht.*
$rendering = new Rendering(
    $nodes,
    $framework,
    $registry,
    new SeededTypeNodes($nodes, $framework),
    new Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale()),
    null,
    $model,
    $relations
);

$merkmal = [
    'slider'   => 'type="range"',
    'toggle'   => 'taxmod-toggle',
    'spinner'  => 'type="number"',
    'field'    => 'type="text"',
    'checkbox' => 'type="checkbox"',
    'color'    => 'type="color"',
];

$geprueft = 0;
$mitNamen = 0;
$traeger  = 0;
$daneben  = [];

foreach ($wpdb->get_results(
    // ⚠️ **Die Kandidaten sind seit TASK-057 wieder die Knoten mit einer Wertzeile an der
    // Einstellungskante `renderer`** ([D-642](../../docs/NewConcept/90-decision-log.md)). *Sie waren
    // es schon einmal; TASK-020 hatte sie auf «gefuellte Spalte» umgestellt, und die Spalte ist
    // gefallen. **Der Traeger ist wieder eine Wertzeile, weil er das immer sein sollte.***
    $wpdb->prepare(
        'SELECT DISTINCT halter.node_id
           FROM ' . Schema::table('node_records') . ' halter
           JOIN ' . Schema::table('relation_records') . " wert ON wert.node_record_id = halter.id
          WHERE wert.relation_id = %d AND wert.value_ref_kind = 'record'",
        $framework->settingRelationId(SettingKey::Renderer)
    ),
    ARRAY_A
) ?: [] as $z) {
    $node = $nodes->find((int) $z['node_id']);

    if ($node === null) {
        continue;
    }

    ++$traeger;

    $name = $rendering->rendererNameFor($node);

    if ($name !== null) {
        ++$mitNamen;
    }

    if ($name === null || ! isset($merkmal[$name])) {
        continue;
    }

    $gezeichnet = $rendering->valueOfType($node, Purpose::Edit);

    if ($gezeichnet === null) {
        continue;
    }

    ++$geprueft;

    if (! str_contains($gezeichnet->markup, $merkmal[$name])) {
        $daneben[] = "{$node->name}: sagt «{$name}», zeichnet ohne «{$merkmal[$name]}»";
    }
}

check('die Zeichnung traegt das Merkmal des gesetzten Renderers', $daneben === [], implode(' · ', array_slice($daneben, 0, 4)));

// ⚠️ *Der Gegenfall: es wurde überhaupt etwas geprüft. Ohne ihn wäre «keine Abweichung» auch dann grün,
// wenn kein einziger Knoten einen Renderer trägt.*
//
// WICHTIG: Der Gegenfall zaehlt seit heute die *aufgeloesten* Wahlen und nicht mehr die
// gezeichneten -- und das ist eine sichtbare Aenderung dieser Zusage (PR-9). Er stand auf
// «>= 3 gezeichnete» aus einer Zeit, in der die Wahl an einer Wertzeile hing. Seit TASK-020
// steht sie in `nodes.settings_record_id` (D-584), und gemessen am 2026-09-05 tragen **28
// Knoten** die Spalte -- aber nur *einer* davon einen Renderer, der ein einzelnes Feld
// zeichnet (`Integer` auf `slider`). Die uebrigen 27 sind `form`, `table`, `chooser-inline`,
// `node`, `compact`, `chooser-dialog`: sie zeichnen einen Rahmen, kein Feld, und
// `valueOfType()` gibt fuer sie nichts zurueck. Eine Zahl, die drei gezeichnete Felder
// verlangt, misst damit nicht mehr, ob der Umzug gehalten hat -- sie misst, wie viele
// skalare Knoten der Eigentuemer gerade eingestellt hat.
//
// Die schaerfere Frage ist die, auf die es ankommt: **loest jede Spalte zu einem Renderer
// auf?** Faellt eine Wahl bei einem Umzug weg, faellt diese Zahl sofort.
// ⚠️ **Die Zahl war an seinen Bestand gebunden und ist es seit dem 2026-09-06 nicht mehr**
// ([`waechter-bestand.md`](../../docs/pakete/modelltabellen/waechter-bestand.md)). *Sie stand auf
// «mindestens 20 von 28» — einer Momentaufnahme davon, wie viele Knoten er gerade eingestellt hatte.
// **Eine solche Zahl misst nicht die Regel, sondern seine Arbeit**, und sie wird rot, sobald er einen
// Renderer wegnimmt, ohne dass etwas kaputt wäre.*
//
// ⚠️ **Die Zusage, auf die es ankommt, ist eine Invariante:** *jeder Träger, den es **gibt**, löst zu
// einem Renderer auf. Fällt eine Wahl bei einem Umzug weg, bleibt der Träger stehen und zeigt ins
// Leere — genau das fällt hier auf, unabhängig davon, wie viele es sind.*
//
// ⚠️ *Und der Gegenfall bleibt: **null** Träger wäre keine grüne Antwort, sondern eine leere Wiese.*
$ohneNamen = $traeger - $mitNamen;

check(
    'jeder Traeger an der Kante loest zu einem Renderer auf',
    $traeger > 0 && $ohneNamen === 0,
    $traeger === 0 ? 'kein einziger Traeger' : $ohneNamen . ' von ' . $traeger . ' loesen ins Leere'
);

check('und mindestens eine Zeichnung war darunter', $geprueft >= 1, (string) $geprueft);

echo "\n== Die Auswahl bietet nur, was der Knoten vertraegt ==\n";

// ⚠️ **Sein Befund an `Integer`:** *«Integer sieht jetzt alle Renderer, wobei nur int-Renderer ok
// wären»* — und die Präzisierung: *«allgemeiner `field` wäre auch noch ok».* *[D-540](../../docs/NewConcept/90-decision-log.md)
// liefert die Möglichkeiten aus dem **Modell** (alle Blätter unter `Renderer`), `R14a` verengt sie auf
// die **brauchbaren**. Vorher waren es 17.*
//
// ⚠️ *Die Erwartung kommt aus der Registratur, nicht aus einer Liste in dieser Datei — sonst würde die
// Zusage rot, sobald ein Renderer dazukommt.*
// WICHTIG: Gefragt wird die Registratur und nicht mehr das Auswahlfeld der Seite -- eine
// sichtbare Aenderung dieser Zusage (PR-9), und der Grund ist gemessen. Die Zusage suchte ein
// `<select name="taxmod_part[<Satz>][44091]">`, also die zweistufige Adresse aus dem Huellknoten
// `DisplayOption`. Den hat der Eigentuemer geloescht; mit ihm sind die Kanten 44091/44093
// gefallen, und `Root` traegt seither ueberhaupt keine Einstellungskante `renderer` mehr
// (gemessen am 2026-09-05: an `Root` stehen `validator` und `read_only`, sonst nichts). Der
// Renderer haengt seit TASK-020 an `nodes.settings_record_id` (D-584).
//
// ⚠️ **Was diese Zusage damit *nicht* mehr abdeckt, und es ist als Befund festzuhalten statt
// gruen zu faerben:** *auf der Knotenseite wird heute **kein Renderer-Waehler gezeichnet**, weil
// `Rendering::settingControl()` je Einstellungs*kante* zeichnet und es keine gibt. Die Zusage
// prueft darum den Inhalt der Regel -- `R14a`: die Auswahl bietet nur, was der Knoten vertraegt --
// an der Stelle, an der er heute lebt. **Dass die Bedienung dazu fehlt, ist eine Luecke des
// Umbaus und gehoert ins Eingangsblatt, nicht in eine Zusage, die so tut, als gaebe es sie.***
// ⚠️ **Und der Preis dieser Aenderung, ausgesprochen:** *vorher standen zwei unabhaengige Wege
// gegeneinander -- die Registratur gegen die Seite. Bleibt nur die Registratur, waere ein Vergleich
// mit sich selbst eine Tautologie. Also steht die Erwartung hier als Liste, gemessen am 2026-09-05.
// **Kommt ein Renderer dazu, wird die Zeile rot und will angesehen werden** -- das ist gewollt und
// ist genau das, was der frueheren Fassung an dieser Stelle abging.*
$erwartungen = [
    'Integer' => ['field', 'slider', 'spinner'],
    'Boolean' => ['checkbox', 'toggle'],
];

foreach ($erwartungen as $name => $soll) {
    $node = knoten($name);

    if ($node === null) {
        check("«{$name}» steht im Modell", false);

        continue;
    }

    $gezeigt = [];

    foreach ($rendering->choicesForNode($node, Purpose::Edit) as $einer) {
        $gezeigt[] = $einer->name();
    }

    sort($soll);
    sort($gezeigt);

    check(
        "«{$name}»: die Auswahl ist genau die zulaessige Menge",
        $gezeigt === $soll,
        'geboten: ' . implode(', ', $gezeigt) . ' — zulaessig: ' . implode(', ', $soll)
    );
}

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
