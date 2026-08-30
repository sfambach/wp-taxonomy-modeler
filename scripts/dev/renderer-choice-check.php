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
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\Rendering;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingRepository;
use Taxmod\WordPress\SystemClock;

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
$edges     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, new TableIdentityAllocator(), $log);
$settings  = new Settings(new WpdbSettingRepository(), $nodes, $framework);
$registry  = ShippedRenderers::registry();

// ⚠️ **Die Prüfung geht denselben Weg wie die Anwendung, und das ist der Punkt.** *Sie fragt beide
// Quellen und lässt die neue gewinnen — genau wie {@see \Taxmod\Core\Service\Rendering}. **Fragte sie
// nur die alte, würde sie nach dem Umzug rot, obwohl die Oberfläche stimmt** — und wäre damit
// wertlos für das, wofür sie gebaut wurde.*
$model = new ModelValues(new WpdbRecordRepository(), $edges, $nodes, $framework);

/** @return array<string,\Taxmod\Core\Model\ResolvedSetting> */
function beideQuellen(\Taxmod\Core\Model\Node|\Taxmod\Core\Model\Relation $subject): array
{
    global $settings, $model;

    return $subject instanceof \Taxmod\Core\Model\Node
        ? [...$settings->resolve($settings->chainFor($subject)), ...$model->forNode($subject)]
        : [...$settings->resolve($settings->chainForUseSite($subject)), ...$model->forUseSite($subject)];
}

/** Der Knoten mit diesem Namen, oder null. */
function knoten(string $name): ?\Taxmod\Core\Model\Node
{
    global $wpdb, $nodes;

    $id = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . Schema::table('nodes') . ' WHERE name = %s LIMIT 1',
        $name
    ));

    return $id === 0 ? null : $nodes->find($id);
}

echo "\n== Welchen Renderer ein Knoten bekommt ==\n";

// ⚠️ *Der erwartete Wert ist das, was am 2026-08-30 herauskam — festgenagelt, damit ein Umzug ihn
// nicht unbemerkt verliert.*
$erwartet = [
    'Base units' => 'chooser-inline',
    'Passiv'     => 'form',
    'Integer'    => 'spinner',
    'Dimension'  => 'node',
    'Prefixes'   => 'chooser-dialog',
    'Parts List' => 'form',
];

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

    check("«{$name}» zeichnet mit «{$soll}»", ($gewaehlt?->name() ?? null) === $soll, $gewaehlt?->name() ?? 'nichts');
}

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
$mitPunkt = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('record_values') . " WHERE path LIKE '%.%'"
);

check('kein Kanten-Datensatz mit zweistufigem Pfad', $mitPunkt === 0, (string) $mitPunkt);

// ⚠️ *Der Gegenfall: es gibt überhaupt Kanten-Datensätze. Sonst wäre «keine zweistufigen» auch dann
// grün, wenn die Tabelle leer wäre.*
$alle = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('record_values'));

check('und es gibt Kanten-Datensaetze', $alle > 50, (string) $alle);

$stellen = [];

foreach ($stellen as [$vonName, $feldName, $soll]) {
    $von = knoten($vonName);

    if ($von === null) {
        check("«{$vonName}» steht im Modell", false, 'kein Knoten dieses Namens');

        continue;
    }

    $kante = null;

    foreach ($edges->fieldEdgesOf([...$von->ancestorIds(), $von->id]) as $eine) {
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
    $settings,
    $registry,
    new SeededTypeNodes($nodes, $framework),
    new Labels(new WpdbLabelRepository(), $framework),
    null,
    $model,
    $edges
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
$daneben  = [];

foreach ($wpdb->get_results(
    'SELECT DISTINCT s.node_id FROM ' . Schema::table('records') . ' s
     INNER JOIN ' . Schema::table('record_values') . ' w ON w.record_id = s.id
     WHERE w.value_ref IS NOT NULL',
    ARRAY_A
) ?: [] as $z) {
    $node = $nodes->find((int) $z['node_id']);

    if ($node === null) {
        continue;
    }

    $name = $rendering->rendererNameFor($node);

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
check('und es wurden Knoten geprueft', $geprueft >= 3, (string) $geprueft);

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
