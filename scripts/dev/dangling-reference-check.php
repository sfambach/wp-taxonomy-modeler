<?php declare(strict_types=1);
/**
 * Ein Verweis auf einen verschwundenen Knoten ist **am Feld** sichtbar — nicht in einer Liste woanders.
 *
 *     php scripts/dev/dangling-reference-check.php [path/to/wordpress]
 *
 * ⚠️ **Sein Wort** ([D-604](../../docs/NewConcept/90-decision-log.md), TASK-038): *«bei nein haben
 * wir Leichen im Baum, die auf nichts mehr zeigen — das muss sichtbar sein, **also am Feld in der
 * Kante**.»* Und dazu die Auslegung derselben Entscheidung: *«nicht eine Liste woanders, die niemand
 * aufschlaegt.»*
 *
 * ⚠️ **Es wird nicht das Aufraeumen geprueft, sondern das Anzeigen.** *Ein Verweis ins Leere ist
 * erlaubt — er ist die Haelfte, die der Benutzer mit «nein» gewaehlt hat. **Falsch waere nur, ihn
 * auszublenden.** Der Waechter loescht darum nichts und meldet auch keine Verweise als Fehler; er
 * legt sich **selbst** eine Leiche an und verlangt, dass sie zu sehen ist.*
 *
 * ⚠️ **Zwei Wege, und der zweite fehlte.** *Die Anzeige zeichnet seit je `#4711` mit
 * `.taxmod-dangling` ({@see \Taxmod\Core\Renderer\ReferenceRenderer}). **Der Auswahldialog zeichnete
 * einen Gedankenstrich** — dasselbe Bild wie «nichts gewaehlt». Genau das ist der Mangel aus D-604:
 * die Leiche war da und sah aus wie eine leere Zeile.*
 *
 * ⚠️ *Gemessen am 2026-09-05: **null** Wertzeilen im Bestand zeigen auf einen verschwundenen Knoten.
 * Der Waechter haette also nichts zu sehen, wenn er nur zaehlte — der Gegenfall ist hier nicht die
 * Zugabe, sondern die ganze Pruefung.*
 *
 * ⚠️ *Eigene Knoten mit dem Vorsatz `__leiche`, am Ende weggeraeumt
 * ([D-613](../../docs/NewConcept/90-decision-log.md), [D-614](../../docs/NewConcept/90-decision-log.md)).
 * Kein Knoten des Eigentuemers wird angefasst.*
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
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\DialogChooserRenderer;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\ReferenceRenderer;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\Section;
use Taxmod\Core\Renderer\Surroundings;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
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
$kanten    = new WpdbRelationRepository();
$records   = new WpdbRecordRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $kanten, $log);
$editor    = new ModelEditor($nodes, $kanten, $framework, $log);
$eingabe   = new DataEntry($records, $kanten, $nodes, $framework, new SystemClock());

echo "\n== 1. Der Bestand — nur gezaehlt, nicht bewertet ==\n";

$werte = Schema::table('relation_records');
$tab   = Schema::table('nodes');

$leichen = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$werte} v
      WHERE v.value_ref_kind = 'node' AND v.value_ref <> 0
        AND NOT EXISTS (SELECT 1 FROM {$tab} n WHERE n.id = v.value_ref)"
);

// ⚠️ *Kein `check()`, weil es kein Fehler ist: ein Verweis ins Leere ist nach D-604 **erlaubt** und
// muss nur zu sehen sein. Die Zahl steht hier, damit sie jemandem auffaellt, wenn sie waechst.*
echo "  gemessen: {$leichen} Wertzeilen zeigen auf einen verschwundenen Knoten\n";

echo "\n== 2. Eine echte Leiche, selbst gelegt ==\n";

/** @var list<int> $gebaut */
$gebaut = [];

$abbauen = function () use (&$gebaut, $wpdb, $records): void {
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
};

try {
    $besitzer = $editor->createNode('__leiche Besitzer', $framework->rootOf(Branch::Model)->id);
    $gebaut[] = $besitzer->id;

    // ⚠️ *Im Konstantenast, denn dort ist der Wert ein **Knotenverweis** — genau die Sorte Wert,
    // die verwaisen kann ({@see Branch::storage()}).*
    $ziel = $editor->createNode('__leiche Ziel', $framework->rootOf(Branch::Constants)->id);
    $zielId = $ziel->id;

    $kante = $editor->addField($besitzer->id, $zielId, '__leiche_feld');

    $satz = $eingabe->create($besitzer->id);
    $eingabe->put($satz->id, $kante->id, TypedValue::ofReference($zielId));

    check('der Wert steht und zeigt auf den Zielknoten', $eingabe->countValues($satz->id, $kante->id) === 1);

    // ⚠️ **Jetzt verschwindet das Ziel, und die Wertzeile bleibt** — *das ist der Fall «nein» aus
    // [D-604](../../docs/NewConcept/90-decision-log.md), und er ist ausdruecklich erlaubt.*
    $nodes->purgeSubtree($ziel);

    check(
        'der Zielknoten ist fort und der Wert steht noch',
        $nodes->find($zielId) === null && $eingabe->countValues($satz->id, $kante->id) === 1,
        'die Leiche ist gar nicht entstanden — dann prueft der Rest nichts'
    );

    echo "\n== 3. Die Anzeige nennt sie beim Mal, nicht als leere Zelle ==\n";

    // ⚠️ *So kommt der Fall beim Renderer an: der Wert traegt einen Verweis, der **Name** kam nicht
    // an, weil der Abstieg ihn nicht aufloesen konnte. Genau die Ableitung, an der beide Renderer
    // ihn erkennen — es gibt keine zweite Angabe, die dasselbe noch einmal sagt.*
    $imBlick = (new ReferenceRenderer())->render(
        $nodes->byId($besitzer->id),
        new RenderContext(
            purpose: Purpose::Display,
            value: TypedValue::ofReference($zielId),
            level: Level::Admin,
            type: SimpleType::NodeRef,
            surroundings: new Surroundings(refersTo: null),
        )
    )->markup;

    check('sie ist markiert', str_contains($imBlick, 'taxmod-dangling'));
    check('und sie nennt, worauf sie zeigte', str_contains($imBlick, '#' . $zielId));

    echo "\n== 4. Und der Bedienweg auch — das war die Luecke ==\n";

    $baum = new Section('', '<span class="row">__leiche</span>');

    $imDialog = (new DialogChooserRenderer())->render(
        $nodes->byId($besitzer->id),
        new RenderContext(
            purpose: Purpose::Edit,
            value: TypedValue::ofReference($zielId),
            level: Level::Admin,
            editable: true,
            fieldName: '__leiche_feld',
            type: SimpleType::NodeRef,
            surroundings: new Surroundings(
                sections: [DialogChooserRenderer::CANDIDATES => $baum]
            ),
        )
    )->markup;

    check('der Auswahldialog markiert sie', str_contains($imDialog, 'taxmod-dangling'));
    check('und nennt sie', str_contains($imDialog, '#' . $zielId));

    // ⚠️ **Der Gegenfall traegt die Zusage.** *Ohne ihn waere «markiert» auch dann wahr, wenn
    // **jede** leere Wahl markiert wuerde — und ein Mal, das ueberall steht, sagt nichts.*
    $ohneWahl = (new DialogChooserRenderer())->render(
        $nodes->byId($besitzer->id),
        new RenderContext(
            purpose: Purpose::Edit,
            value: TypedValue::nothing(),
            level: Level::Admin,
            editable: true,
            fieldName: '__leiche_feld',
            type: SimpleType::NodeRef,
            surroundings: new Surroundings(
                sections: [DialogChooserRenderer::CANDIDATES => $baum]
            ),
        )
    )->markup;

    check('«nichts gewaehlt» bleibt unmarkiert', ! str_contains($ohneWahl, 'taxmod-dangling'));
    check('und ist als «nichts» erkennbar', str_contains($ohneWahl, 'taxmod-nothing'));
} finally {
    $abbauen();
}

echo "\n== 5. Weggeraeumt ==\n";

$rest = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$tab} WHERE name LIKE '__leiche%'"
);

check('kein eigener Knoten bleibt stehen', $rest === 0, "{$rest} stehen noch da");

$danach = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$werte} v
      WHERE v.value_ref_kind = 'node' AND v.value_ref <> 0
        AND NOT EXISTS (SELECT 1 FROM {$tab} n WHERE n.id = v.value_ref)"
);

// ⚠️ *Der Lauf legt eine Leiche an; er darf keine zuruecklassen. **Gemessen wird gegen den Stand
// vor dem Lauf**, nicht gegen null — was dem Eigentuemer gehoert, geht ihn an und nicht mich.*
check(
    'und der Lauf hat keine eigene Leiche hinterlassen',
    $danach === $leichen,
    "vorher {$leichen}, nachher {$danach}"
);

echo "\n{$bad} fehlgeschlagen, {$ok} in Ordnung\n";

exit($bad === 0 ? 0 : 1);
