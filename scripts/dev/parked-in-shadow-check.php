<?php declare(strict_types=1);
/**
 * Parken heisst wandern — die Kante **und ihre Wertzeilen**, und Zurückholen ist die Umkehrung.
 *
 *     php scripts/dev/parked-in-shadow-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-575](../../docs/NewConcept/90-decision-log.md), wörtlich von ihm:** *«Parken heisst: in die
 * Schattentabelle wandern, mit der Änderungsgruppe im Gepäck.»* **Und
 * [D-619](../../docs/NewConcept/90-decision-log.md), auf sein *«1»* gegen «stehenbleiben» und
 * «verbieten»:** *die Wertzeilen einer geparkten Kante wandern mit, beim Zurückholen wieder heraus.
 * Eine Gruppe, ein Akt, umkehrbar.*
 *
 * ```mermaid
 * flowchart LR
 *   K["Kante lebend"] -->|parken| H[("relations_history · mit Gruppe")]
 *   W["ihre Wertzeilen"] -->|mit ihr| V[("relation_records_history")]
 *   H -->|zurueckholen| K
 *   V -->|mit ihr| W
 * ```
 *
 * Geprüft wird sechserlei:
 *
 * 1. **`relations` hat keine Spalte `parked_by_group_id` mehr**, der Schatten hat sie noch.
 * 2. **Parken nimmt die lebende Zeile weg** und legt sie mit ihrer Änderungsgruppe in den Schatten.
 * 3. **Die Wertzeile wandert mit** — lebend fort, im Schatten da.
 * 4. **Die geparkte Kante ist als geparkt lesbar**, und die lebende Liste zeigt sie nicht.
 * 5. **Zurückholen holt beides zurück** — Kante und Wertzeile, mit denselben Ids.
 * 6. **Und danach gilt sie nicht mehr als geparkt.**
 *
 * ⚠️ **Der Wächter legt sich seinen eigenen Fall an.** *Gemessen am 2026-09-05 trägt keine der 14
 * geparkten Kanten eine Wertzeile — die Zusage muss den Fall trotzdem prüfen, sonst prüft sie nur,
 * was zufällig gerade dasteht.* Alles, was er anlegt, trägt den Präfix `__` und wird am Ende
 * weggeräumt; **die Daten des Eigentümers bleiben unberührt.**
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

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Query;
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
$editor    = new ModelEditor($nodes, $relations, $framework, $log);
$records   = new WpdbRecordRepository();
$data      = new DataEntry($records, $relations, $nodes, $framework, new SystemClock());

/** @var array{knoten: list<int>, saetze: list<int>} Alles, was dieser Lauf angelegt hat. */
$meines = ['knoten' => [], 'saetze' => []];

// ⚠️ *Auch bei einem Abbruch — ein roter Lauf darf keine Knoten des Wächters hinterlassen.*
register_shutdown_function(static function () use (&$meines): void {
    global $wpdb;

    foreach ($meines['saetze'] as $id) {
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('node_records') . ' WHERE id = %d', $id));
    }

    foreach ($meines['knoten'] as $id) {
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('relation_records') . ' WHERE relation_id IN
             (SELECT id FROM ' . Schema::table('relations') . ' WHERE from_node_id = %d OR to_node_id = %d)',
            $id,
            $id
        ));
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('relations') . ' WHERE from_node_id = %d OR to_node_id = %d',
            $id,
            $id
        ));
        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('relations_history') . ' WHERE from_node_id = %d OR to_node_id = %d',
            $id,
            $id
        ));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('nodes') . ' WHERE id = %d', $id));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('nodes_history') . ' WHERE id = %d', $id));
    }
});

echo "1 · Die Spalte steht nur noch im Schatten\n";

function spalten(string $tabelle): array
{
    return array_map(
        static fn (array $z): string => (string) $z['Field'],
        Query::rows("Spalten von {$tabelle}", 'SHOW COLUMNS FROM ' . Schema::table($tabelle))
    );
}

check('relations hat kein parked_by_group_id mehr', ! in_array('parked_by_group_id', spalten('relations'), true));
check('relations_history hat es noch', in_array('parked_by_group_id', spalten('relations_history'), true));

check(
    'keine lebende Kante behauptet mehr, geparkt zu sein',
    ! in_array('parked_by_group_id', spalten('relations'), true),
    'die Spalte wäre die Behauptung'
);

echo "\n2 · Ein eigener Fall — Knoten, Kante, Wertzeile\n";

$modell = $framework->rootOf(Branch::Model);

// Ein Zielknoten für das Feld: irgendein einfacher Typ unter «Data Types».
$typen = $editor->childrenOf($framework->rootOf(Branch::DataTypes)->id);

if ($typen === []) {
    check('ein einfacher Typ steht bereit', false, 'unter Data Types liegt nichts');

    echo "\n$ok OK, $bad FAIL\n";

    exit(1);
}

$typ = $typen[0];

check('ein einfacher Typ steht bereit', true, $typ->name);

$eigner              = $editor->createNode('__parkprobe', $modell->id);
$meines['knoten'][]  = $eigner->id;

$kante = $editor->addField($eigner->id, $typ->id, '__parkfeld');

check('Kante angelegt', $kante->id > 0, '#' . $kante->id);

$satz               = $data->create($eigner->id);
$meines['saetze'][] = $satz->id;

$data->put($satz->id, $kante->id, TypedValue::ofText('haengt an der Kante'));

$wertzeilen = Query::rows('Wertzeilen der Probekante zählen', $wpdb->prepare(
    'SELECT id FROM ' . Schema::table('relation_records') . ' WHERE relation_id = %d',
    $kante->id
));

check('eine Wertzeile haengt daran', count($wertzeilen) === 1, count($wertzeilen) . ' Zeilen');

$wertIds = array_map(static fn (array $z): int => (int) $z['id'], $wertzeilen);

echo "\n3 · Parken — die Kante und ihre Werte wandern\n";

$editor->removeField($eigner->id, $kante->id);

$lebendeKante = Query::value('lebende Kante suchen', $wpdb->prepare(
    'SELECT id FROM ' . Schema::table('relations') . ' WHERE id = %d',
    $kante->id
));

check('die lebende Kantenzeile ist fort', $lebendeKante === null);

$imSchatten = Query::row('Kante im Schatten suchen', $wpdb->prepare(
    'SELECT parked_by_group_id, deleted FROM ' . Schema::table('relations_history') . '
     WHERE id = %d ORDER BY version DESC LIMIT 1',
    $kante->id
));

check('sie steht im Schatten', $imSchatten !== null);

check(
    'mit der Aenderungsgruppe im Gepaeck',
    $imSchatten !== null && $imSchatten['parked_by_group_id'] !== null && (int) $imSchatten['parked_by_group_id'] > 0,
    'Gruppe: ' . (string) ($imSchatten['parked_by_group_id'] ?? 'keine')
);

$lebendeWerte = Query::rows('lebende Wertzeilen suchen', $wpdb->prepare(
    'SELECT id FROM ' . Schema::table('relation_records') . ' WHERE relation_id = %d',
    $kante->id
));

check('die Wertzeile ist lebend fort', $lebendeWerte === [], count($lebendeWerte) . ' geblieben');

$werteImSchatten = Query::rows('Wertzeilen im Schatten suchen', $wpdb->prepare(
    'SELECT id FROM ' . Schema::table('relation_records_history') . ' WHERE relation_id = %d AND deleted = 1',
    $kante->id
));

check(
    'und sie steht im Schatten',
    array_map(static fn (array $z): int => (int) $z['id'], $werteImSchatten) === $wertIds,
    count($werteImSchatten) . ' im Schatten gegen ' . count($wertIds) . ' erwartete'
);

echo "\n4 · Sie ist als geparkt lesbar, aber nicht als lebend\n";

$geparkte = array_map(static fn ($e): int => $e->id, $relations->parkedFieldRelationsOf([$eigner->id]));
$lebende  = array_map(static fn ($e): int => $e->id, $relations->fieldRelationsOf([$eigner->id]));

check('die geparkte Liste kennt sie', in_array($kante->id, $geparkte, true));
check('die lebende Liste kennt sie nicht', ! in_array($kante->id, $lebende, true));

echo "\n5 · Zurueckholen holt beides zurueck\n";

$editor->restoreField($eigner->id, $kante->id);

$wiederDa = Query::value('zurueckgeholte Kante suchen', $wpdb->prepare(
    'SELECT id FROM ' . Schema::table('relations') . ' WHERE id = %d',
    $kante->id
));

check('die Kante steht wieder lebend da', $wiederDa !== null, 'unter derselben Id ' . $kante->id);

$wiederWerte = Query::rows('zurueckgeholte Wertzeilen suchen', $wpdb->prepare(
    'SELECT id FROM ' . Schema::table('relation_records') . ' WHERE relation_id = %d ORDER BY id',
    $kante->id
));

check(
    'und die Wertzeile mit ihr, unter derselben Id',
    array_map(static fn (array $z): int => (int) $z['id'], $wiederWerte) === $wertIds,
    count($wiederWerte) . ' Zeilen gegen ' . count($wertIds) . ' erwartete'
);

$werte = $records->valuesOf($satz->id);
$texte = array_map(static fn ($w): ?string => $w->value->text, $werte);

check('mit ihrem Inhalt', in_array('haengt an der Kante', $texte, true), implode(', ', array_map(strval(...), $texte)));

echo "\n6 · Und sie gilt nicht mehr als geparkt\n";

$geparkteDanach = array_map(static fn ($e): int => $e->id, $relations->parkedFieldRelationsOf([$eigner->id]));
$lebendeDanach  = array_map(static fn ($e): int => $e->id, $relations->fieldRelationsOf([$eigner->id]));

check('die geparkte Liste kennt sie nicht mehr', ! in_array($kante->id, $geparkteDanach, true));
check('die lebende Liste kennt sie wieder', in_array($kante->id, $lebendeDanach, true));

echo "\n$ok OK, $bad FAIL\n";

exit($bad === 0 ? 0 : 1);
