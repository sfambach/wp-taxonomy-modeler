<?php declare(strict_types=1);
/**
 * Ein Knoten erbt keine Einstellungskante, die auf **ihn selbst** zeigt — und die Zeile bleibt sichtbar.
 *
 *     php scripts/dev/setting-self-inherit-check.php [path/to/wordpress]
 *
 * ⚠️ **Zwei Zusagen, und keine genügt allein** ([D-607](../../docs/NewConcept/90-decision-log.md),
 * [D-608](../../docs/NewConcept/90-decision-log.md)). *Die Sperre allein liesse den Knoten ohne
 * Antwort auf «warum hat `read_only` kein `read_only`»; die Anzeige allein wäre eine Sperre, die
 * nichts sperrt.*
 *
 * ⚠️ **Der Gegenfall trägt hier mehr als die Zusage selbst.** *Die erste Fassung der Regel («ein
 * Knoten mit `kind = setting` erbt nichts», [D-605](../../docs/NewConcept/90-decision-log.md)) wäre
 * bei einer Prüfung, die nur das Sperren misst, **grün** gewesen — und hätte `render with label` den
 * geerbten `converter` genommen. Der Eigentümer hat es gesehen, bevor es gebaut war; hier steht es,
 * damit es niemand ein zweites Mal bauen muss, um es zu merken.*
 *
 * ⚠️ *Gebaut wird auf eigenen Knoten mit dem Vorsatz `__selbsterbe`, weil die Regel an einem
 * **Vorfahren mit Kind** hängt und das Modell des Eigentümers heute nur zwei solche Kanten kennt.
 * Am Ende wird alles wieder abgebaut.*
 *
 * @see docs/NewConcept/90-decision-log.md
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

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\FieldRowRenderer;
use Taxmod\Core\Renderer\Section;
use Taxmod\Core\Renderer\Surroundings;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\ModelValues;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\SystemClock;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
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

$nodes     = new WpdbNodeRepository();
$kanten    = new WpdbRelationRepository();
$records   = new WpdbRecordRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $kanten, $log);
$editor    = new ModelEditor($nodes, $kanten, $framework, $log);
$eingabe   = new DataEntry($records, $kanten, $nodes, $framework, new SystemClock());

/** @var list<int> */
$gebaut = [];

function abbauen(): void
{
    global $wpdb, $gebaut, $records;

    foreach (array_reverse($gebaut) as $id) {
        foreach ($records->ofNode($id) as $satz) {
            $records->forgetRecord($satz->id);
        }

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('relations') . ' WHERE from_node_id = %d OR to_node_id = %d',
            $id,
            $id
        ));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('nodes') . ' WHERE id = %d', $id));
    }

    $gebaut = [];
}

echo "\n== Der Aufbau: ein Vorfahr, seine Einstellung als Kind, ein Geschwister ==\n";

try {
    $vorfahr = $editor->createNode('__selbsterbe Vorfahr', $framework->rootOf(Branch::Model)->id);
    $gebaut[] = $vorfahr->id;

    // ⚠️ *Das Ziel liegt **unter** dem Vorfahren — genau die Lage von `Root --read_only--> read_only`.*
    $ziel = $editor->createNode('__selbsterbe ziel', $vorfahr->id);
    $gebaut[] = $ziel->id;

    // ⚠️ *Das Geschwister ist der Gegenfall: es erbt dieselbe Kante und muss sie behalten.*
    $geschwister = $editor->createNode('__selbsterbe Geschwister', $vorfahr->id);
    $gebaut[] = $geschwister->id;

    $kante = $editor->addField($vorfahr->id, $ziel->id, '__selbsterbe_feld');
    $editor->markAsSetting($vorfahr->id, $kante->id, true);

    // ⚠️ **Ohne Wert misst die Kette gar nichts.** *Eine Einstellung ohne Wert fehlt in beiden
    // Fällen, und die Prüfung wäre grün, weil nichts da ist — nicht, weil etwas greift.*
    $eingabe->putSettingAt($vorfahr->id, $kante->id, 0, TypedValue::ofText('__selbsterbe_wert'));

    check('der Aufbau steht', true);

    $werte = new ModelValues($records, $kanten, $nodes, $framework);

    echo "\n== Die Sperre (D-607) ==\n";

    $amZiel = $werte->forNode($nodes->byId($ziel->id));
    check(
        'der Zielknoten erbt seine eigene Einstellungskante nicht',
        ! isset($amZiel['__selbsterbe_feld']),
        'sie steht trotzdem da'
    );

    echo "\n== Der Gegenfall — ohne ihn waere die Sperre auch grün, wenn nichts mehr erbt ==\n";

    $werte2       = new ModelValues($records, $kanten, $nodes, $framework);
    $amGeschwister = $werte2->forNode($nodes->byId($geschwister->id));
    check(
        'ein Geschwister erbt dieselbe Kante weiterhin',
        isset($amGeschwister['__selbsterbe_feld']),
        'die Vererbung ist mit gesperrt worden — das ist die zurueckgenommene Fassung D-605'
    );

    $werte3 = new ModelValues($records, $kanten, $nodes, $framework);
    $amVorfahr = $werte3->forNode($nodes->byId($vorfahr->id));
    check(
        'der erklaerende Vorfahr behaelt seine Angabe',
        isset($amVorfahr['__selbsterbe_feld']),
        'auch der Erklaerer hat sie verloren'
    );

    echo "\n== Die Regel selbst, an ihrer einen Stelle ==\n";

    $gelesen = $kanten->byId($kante->id);

    check('sie greift am Ziel', ModelValues::inheritanceBlocked($gelesen, $ziel->id));
    check('sie greift nicht am Geschwister', ! ModelValues::inheritanceBlocked($gelesen, $geschwister->id));
    // ⚠️ *Es geht ums **Erben**, nicht ums Haben: eine Kante, die jemand absichtlich von einem
    // Knoten auf sich selbst legt, bleibt erlaubt ([D-608](../../docs/NewConcept/90-decision-log.md)).*
    check('sie greift nicht am Erklaerer selbst', ! ModelValues::inheritanceBlocked($gelesen, $vorfahr->id));

    echo "\n== Die Anzeige (D-608) — die Zeile bleibt und sagt, dass sie gesperrt ist ==\n";

    $zeile = new FieldRowRenderer();

    $gesperrt = $zeile->render($gelesen, new RenderContext(
        purpose: Purpose::Edit,
        value: TypedValue::nothing(),
        editable: false,
        surroundings: new Surroundings(
            refersTo: '__selbsterbe ziel',
            sections: [FieldRowRenderer::VALUE => new Section('', '<input name="x">')],
            locked: true
        ),
    ))->markup;

    $offen = $zeile->render($gelesen, new RenderContext(
        purpose: Purpose::Edit,
        value: TypedValue::nothing(),
        editable: false,
        surroundings: new Surroundings(
            refersTo: '__selbsterbe ziel',
            sections: [FieldRowRenderer::VALUE => new Section('', '<input name="x">')],
            locked: false
        ),
    ))->markup;

    check('die gesperrte Zeile wird ueberhaupt gezeichnet', str_contains($gesperrt, '<tr'));
    check('sie ist als gesperrt gekennzeichnet', str_contains($gesperrt, 'taxmod-field-locked'));
    check('«gesperrt» steht sichtbar in der Zeile', str_contains($gesperrt, '>locked<') || str_contains($gesperrt, 'taxmod-locked'));
    check('ein Hinweistext nennt den Grund', str_contains($gesperrt, 'title="'));
    check('sie traegt kein Eingabefeld mehr', ! str_contains($gesperrt, '<input name="x">'));
    check('eine ungesperrte Zeile traegt ihres weiterhin', str_contains($offen, '<input name="x">'));
    check('und sie ist nicht gekennzeichnet', ! str_contains($offen, 'taxmod-field-locked'));
} finally {
    abbauen();
}

echo "\n{$bad} fehlgeschlagen, {$ok} in Ordnung\n";

exit($bad === 0 ? 0 : 1);
