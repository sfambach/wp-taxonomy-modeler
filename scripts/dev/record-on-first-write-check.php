<?php declare(strict_types=1);

/**
 * Entsteht ein Datensatz erst beim Schreiben — und nicht schon beim Ansehen oder Loeschen?
 *
 * [D-609](../../docs/NewConcept/90-decision-log.md), TASK-043, BUG-004. **Sein Wort:** *«ein Datensatz
 * entsteht beim ersten Schreiben, nicht beim Ansehen? ja bitte.»*
 *
 * ⚠️ **Eine Grenzpruefung, weil die Zahl aus der Datenbank kam.** *Gemessen waren 324 von 377
 * Datensaetzen ohne eine einzige Wertzeile — angelegt von `clearSettingAt()`, das seinen Satz holte,
 * bevor es merkte, dass nichts zu loeschen ist. **Eine Seite zu speichern, auf der ein
 * Einstellungsfeld leer ist, legte damit einen Datensatz an.***
 *
 * ⚠️ **Eigene Knoten, kein Name aus seinem Modell** ([D-613](../../docs/NewConcept/90-decision-log.md)):
 * angelegt, geprueft, weggeraeumt — und weggeraeumt wird nach dem eigenen Namensmuster, nie ueber
 * `clearTrash()`.
 *
 * Usage: php scripts/dev/record-on-first-write-check.php C:/Devel/Wordpress
 *
 * @see docs/NewConcept/90-decision-log.md
 */

$wordpress = $argv[1] ?? 'C:/Devel/Wordpress';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende
// alles zurück, auch nach einem Abbruch. Siehe `lib/no-write.php` und `tests/README.md`.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

// ⚠️ *Seit TASK-019 traegt jeder Knoten eine Beschriftungszeile ([D-580](../../docs/NewConcept/90-decision-log.md)) —
// und dieser Lauf raeumt Knoten mit rohem SQL weg, also am Ende hinter sich her. **Es faellt nur,
// worauf weder ein Knoten noch eine Kante zeigt.***
register_shutdown_function(static fn (): int => \Taxmod\WordPress\Persistence\Schema::forgetOrphanLabels());

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;

        echo "  ok   {$what}\n";

        return;
    }

    $failed++;

    echo "  FAIL {$what}" . ($detail === '' ? '' : " — {$detail}") . "\n";
}

$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$relations     = new WpdbRelationRepository();
$rows      = new WpdbRecordRepository();
$labelRows = new WpdbLabelRepository();
$framework = new SeededFrameworkNodes($nodes, $relations, $log);

$editor = new ModelEditor($nodes, $relations, $framework, $log, $labelRows, $rows);
$data   = new DataEntry($rows, $relations, $nodes, $framework, new SystemClock());

global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

/** Wie viele Datensaetze dieser Knoten traegt — die einzige Frage dieses Laufs. */
function saetze(int $nodeId): int
{
    global $wpdb, $p;

    return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}node_records WHERE node_id = {$nodeId}");
}

echo "\n== ein eigener Knoten mit einem eigenen Feld ==\n";

$typen  = $framework->rootOf(Branch::DataTypes);
$ziel   = $editor->createNode('__rw ziel', $typen->id);
$traeger = $editor->createNode('__rw traeger', $typen->id);
$feld   = $editor->addField($traeger->id, $ziel->id, 'probe');

check('er hat noch keinen Datensatz', saetze($traeger->id) === 0, (string) saetze($traeger->id));

echo "\n== ansehen und loeschen legen nichts an ==\n";

// ⚠️ *Genau der Weg aus BUG-004: die Maske speichert ein leeres Einstellungsfeld.*
$data->clearSettingAt($traeger->id, $feld->id, 0);

check('Loeschen legt keinen Datensatz an', saetze($traeger->id) === 0, (string) saetze($traeger->id));

$data->settingValuesOf($traeger->id, [$feld->id]);

check('Lesen legt keinen Datensatz an', saetze($traeger->id) === 0, (string) saetze($traeger->id));

echo "\n== das erste Schreiben legt ihn an ==\n";

// ⚠️ **Die Gegenprobe, ohne die die Zusage auch ein Schreiber erfuellen wuerde, der gar nichts tut.**
$data->putSettingAt($traeger->id, $feld->id, 0, TypedValue::ofText('probewert'));

check('Schreiben legt genau einen Datensatz an', saetze($traeger->id) === 1, (string) saetze($traeger->id));

$data->putSettingAt($traeger->id, $feld->id, 0, TypedValue::ofText('zweiter wert'));

check('ein zweites Schreiben legt keinen zweiten an', saetze($traeger->id) === 1, (string) saetze($traeger->id));

echo "\n== kein leerer default bleibt bestehen ==\n";

// ⚠️ **[D-653](../../docs/NewConcept/90-decision-log.md), und das ist die Zusage zur einmaligen
// Wanderung** (Fassung 36). *Sein Wort: «der default-Satz sollte nicht leer bestehen.» **Gemessen am
// 2026-09-06 waren 390 von 454 leer**; die Wanderung hat 368 davon fallen lassen — der Rest war
// inzwischen dazugekommen und faellt beim naechsten Lauf.*
//
// ⚠️ **Ausser denen, auf die eine Wertzeile zeigt, und die Ausnahme ist gemessen**
// ([D-610](../../docs/NewConcept/90-decision-log.md)): *ein leerer Satz kann die **Renderer-Wahl**
// sein — «compact ist gewaehlt, nichts daran eingestellt», und die Aussage steckt in seiner
// `node_id`. **Wer ihn als leer wegraeumt, loescht eine Wahl.***
$leerUndFrei = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$p}node_records s
      WHERE s.record_type = 'default'
        AND NOT EXISTS (SELECT 1 FROM {$p}relation_records v WHERE v.node_record_id = s.id)
        AND NOT EXISTS (SELECT 1 FROM {$p}relation_records h WHERE h.value_ref = s.id AND h.value_ref_kind = 'record')"
);

check('kein leerer, ungehaltener default-Satz steht mehr da', $leerUndFrei === 0, (string) $leerUndFrei);

// ⚠️ *Und die Umkehrbarkeit: was gefallen ist, liegt im Schatten und steht in **einer**
// Aenderungsgruppe ([D-348](../../docs/NewConcept/90-decision-log.md)).*
$gefallen = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$p}changelog WHERE what = 'empty default record dropped'"
);

// ⚠️ **«Ein Lauf ist eine Gruppe», nicht «es gab je nur eine».** *Meine erste Fassung sagte
// `COUNT(DISTINCT change_group_id) === 1` und wurde rot, sobald ein anderer Waechter
// {@see \Taxmod\WordPress\Persistence\Schema::install()} noch einmal rief: der Schritt ist
// **zweimal ausfuehrbar** und raeumt dann die inzwischen entstandenen weg — jedes Mal in einer
// eigenen Gruppe, und das ist richtig. **Die Zusage ist deshalb die grosse Wanderung:** ihre 368
// Saetze stehen zusammen, und keiner steht ohne Gruppe.*
$groesste = (int) $wpdb->get_var(
    "SELECT COUNT(*) c FROM {$p}changelog WHERE what = 'empty default record dropped'
      GROUP BY change_group_id ORDER BY c DESC LIMIT 1"
);

$ohneGruppe = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$p}changelog
      WHERE what = 'empty default record dropped' AND (change_group_id IS NULL OR change_group_id = 0)"
);

check('die Wanderung steht im Aenderungsbuch', $gefallen > 0, (string) $gefallen);
check('die einmalige Wanderung steht in einer Gruppe', $groesste >= 300, (string) $groesste);
check('und kein gefallener Satz steht ohne Gruppe', $ohneGruppe === 0, (string) $ohneGruppe);

$imSchatten = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$p}node_records_history h
      WHERE h.id IN (SELECT owner_id FROM {$p}changelog WHERE what = 'empty default record dropped')"
);

check('und jeder gefallene Satz liegt im Schatten', $imSchatten >= $gefallen, "{$imSchatten} bei {$gefallen}");

echo "\n== und der Zeichenweg nimmt den example, wenn der default leer ist ==\n";

// ⚠️ **Die andere Haelfte von [D-653](../../docs/NewConcept/90-decision-log.md):** *«Wenn es ein
// default gibt und der gefuellt ist, soll er den zum Rendern verwenden, ansonsten einen
// example-Satz.»* **Sein Anlass, gemessen an seinem Bildschirm:** *die Vorschau sagte «Filled from
// record #4756» und zog aus dem `default` — **ein leerer Satz gewann gegen ein gefuelltes Beispiel
// und zeigte nichts.***
$zeichner = new \Taxmod\Core\Service\Rendering(
    $nodes,
    $framework,
    \Taxmod\Core\Renderer\ShippedRenderers::registry(),
    new \Taxmod\WordPress\Persistence\SeededTypeNodes($nodes, $framework),
    new \Taxmod\Core\Service\Labels(new WpdbLabelRepository(), \Taxmod\WordPress\Admin\SettingsScreen::neutralLocale()),
    null,
    new \Taxmod\Core\Service\ModelValues(new WpdbRecordRepository(), $relations, $nodes, $framework)
);

// ⚠️ *Ein **eigener** Knoten: `__rw traeger` traegt aus dem Abschnitt darueber schon einen
// gefuellten `default`, und der wuerde die Frage beantworten, bevor sie gestellt ist.*
$schau      = $editor->createNode('__rw schaufenster', $typen->id);
$schauFeld  = $editor->addField($schau->id, $ziel->id, 'probe');

$leererDefault = $data->create($schau->id, \Taxmod\Core\Model\RecordType::Default);
$beispiel      = $data->create($schau->id, \Taxmod\Core\Model\RecordType::Example);

$data->put($beispiel->id, $schauFeld->id, TypedValue::ofText('schaufenster'));

$alle    = $data->recordsOf($schau->id);
$gefuellt = $data->filledAmong(array_map(static fn ($s): int => $s->id, $alle));

check(
    'ein leerer default zaehlt nicht als vorhanden — der example zeichnet',
    $zeichner->previewRecordAmong($alle, $gefuellt)?->id === $beispiel->id,
    'gewaehlt #' . ($zeichner->previewRecordAmong($alle, $gefuellt)?->id ?? 0)
        . ", leerer default #{$leererDefault->id}, example #{$beispiel->id}"
);

// ⚠️ *Die Gegenprobe: sobald der `default` etwas traegt, gewinnt er wieder. **Ohne sie waere die
// Zusage oben auch von einem Leser zu erfuellen, der `default` gar nicht mehr kennt.***
$data->put($leererDefault->id, $schauFeld->id, TypedValue::ofText('vorgabe'));

$alle     = $data->recordsOf($schau->id);
$gefuellt = $data->filledAmong(array_map(static fn ($s): int => $s->id, $alle));

check(
    'ein gefuellter default gewinnt gegen den example',
    $zeichner->previewRecordAmong($alle, $gefuellt)?->id === $leererDefault->id,
    'gewaehlt #' . ($zeichner->previewRecordAmong($alle, $gefuellt)?->id ?? 0)
);

echo "\n== aufraeumen ==\n";

// ⚠️ *Nach dem eigenen Namensmuster und nie ueber `clearTrash()` — dort liegt seine geparkte Arbeit
// (TASK-039). Nach Namen, nicht nur nach den Ids dieses Laufs: ein abgestuerzter Lauf laesst sonst
// Reste stehen, die der naechste als eigenen Fehlschlag meldet.*
$meine = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}nodes_named WHERE name LIKE '\\_\\_rw %'"));
$in    = $meine === [] ? (string) $ziel->id : implode(',', $meine);

$wpdb->query("DELETE FROM {$p}relation_records WHERE node_record_id IN (SELECT id FROM {$p}node_records WHERE node_id IN ({$in}))");
$wpdb->query("DELETE FROM {$p}node_records WHERE node_id IN ({$in})");
$wpdb->query("DELETE FROM {$p}labels WHERE owner_id IN ({$in})");
$wpdb->query("DELETE FROM {$p}relations WHERE from_node_id IN ({$in}) OR to_node_id IN ({$in})");
$wpdb->query("DELETE FROM {$p}nodes WHERE id IN ({$in})");

check(
    'der Waechter laesst nichts zurueck',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}nodes_named WHERE name LIKE '\\_\\_rw %'") === 0
);

printf("\n%d ok, %d failed\n", $passed, $failed);

exit($failed === 0 ? 0 : 1);
