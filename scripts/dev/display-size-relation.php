<?php declare(strict_types=1);

/**
 * Die Einstellungskante `Text --display_size--> Integer` anlegen —
 * [D-659](../../docs/NewConcept/90-decision-log.md).
 *
 * **Sein Wort:** *«an Text-Typ ein Setting `display size` in Zeichen einfuehren, damit Strasse die
 * gross ist und Hausnummer die klein ist.»* **Der Schluessel ist mit dieser Aenderung im Kern und
 * der Renderer beruecksichtigt ihn — was fehlt, ist der Ort im Modell, an dem eine Angabe entstehen
 * kann.** *Genau derselbe Zustand, den `min` und `max` vor `minmax-specialize.php` hatten: ein
 * Schluessel ohne Kante ist ein Angebot, das niemand bedienen kann.*
 *
 * ⚠️ **Das Ziel ist ein eigener Knoten `display size` unter `Integer`, und das ist gemessen und
 * nicht gewaehlt.** *Der erste Lauf zeigte auf `Integer` selbst — wie `Prefixes --exponent-->
 * Integer` — und der Schreiber wies ihn ab: **`Integer` hat seit `minmax-specialize.php` eigene
 * Felder (`min`, `max`), und eine Einstellungskante auf ein Ziel mit eigenen Feldern verlangt einen
 * eigenen Teil** ({@see \Taxmod\Core\Service\DataEntry::ownsItsRecord()},
 * [D-540](../../docs/NewConcept/90-decision-log.md)). Ein Wert haette dort nicht hingeschrieben
 * werden koennen. **`min` und `max` haben genau darum ihre eigenen, feldlosen Zielknoten**, und
 * `display size` bekommt seinen aus demselben Grund.*
 *
 * ⚠️ *Am Typ erklaert und je Feld ueberschreibbar: die Kante haengt an `Text`, und ueber die
 * Aufloesungskette ([D-602](../../docs/NewConcept/90-decision-log.md)) sehen sie alle Felder, die
 * auf `Text` zeigen — `Street Name` und `House Number` darunter. Wer je Feld etwas anderes will,
 * ueberschreibt es an der Verwendungsstelle ([D-611](../../docs/NewConcept/90-decision-log.md)).*
 *
 * ⚠️ *Probelauf ist die Voreinstellung. Ohne `--go` wird nichts geschrieben. Ein zweiter Lauf findet
 * die Kante und legt nichts doppelt an.*
 *
 * Aufruf: php scripts/dev/display-size-relation.php          (nur zeigen)
 *         php scripts/dev/display-size-relation.php --go     (schreiben, in einer Transaktion)
 *
 * @see docs/pakete/modelltabellen/tasks.md
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';

define('WP_USE_THEMES', false);

require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

$go = in_array('--go', $argv, true);

global $wpdb;

$nodesTable     = Schema::table('nodes_named');
$relationsTable = Schema::table('relations_named');
$schluessel     = 'display_size';

// ---------------------------------------------------------------- messen

$text = $wpdb->get_row("SELECT id, name FROM {$nodesTable} WHERE name = 'Text'");
$zahl = $wpdb->get_row("SELECT id, name FROM {$nodesTable} WHERE name = 'Integer'");

if ($text === null || $zahl === null) {
    fwrite(STDERR, "ABBRUCH: `Text` oder `Integer` ist nicht auffindbar.\n");

    exit(1);
}

$vorhanden = $wpdb->get_row($wpdb->prepare(
    "SELECT id, kind, to_node_id FROM {$relationsTable} WHERE from_node_id = %d AND name = %s",
    (int) $text->id,
    $schluessel
));

// ⚠️ *Der Zielknoten heisst wie die Angabe und liegt unter `Integer` — dieselbe Anordnung wie
// `min` und `max`. Steht er schon da, wird er genommen und kein zweiter angelegt.*
//
// ⚠️ *Die Spalte heisst `parent_node_id`. **Sie hiess in der ersten Fassung dieses Skripts
// `parent_id`, und `$wpdb` hat dazu geschwiegen** — die Abfrage lieferte `null`, das Skript las das
// als «gibt es noch nicht» und legte den Knoten ein zweites Mal an. Darum steht die Prüfung auf
// `last_error` darunter: eine kaputte Abfrage und ein leeres Ergebnis sehen gleich aus.*
$ziel = $wpdb->get_row($wpdb->prepare(
    "SELECT id FROM {$nodesTable} WHERE name = %s AND parent_node_id = %d",
    'display size',
    (int) $zahl->id
));

if ($wpdb->last_error !== '') {
    fwrite(STDERR, 'ABBRUCH: Abfrage kaputt — ' . $wpdb->last_error . "\n");

    exit(2);
}

printf("`Text` ist %d, `Integer` ist %d\n", (int) $text->id, (int) $zahl->id);

if (
    $vorhanden !== null
    && $ziel !== null
    && (int) $vorhanden->to_node_id === (int) $ziel->id
    && (string) $vorhanden->kind === 'setting'
) {
    printf(
        "\nDie Kante steht schon: %d, Art `%s`, Ziel %d. Es gibt nichts zu tun.\n",
        (int) $vorhanden->id,
        (string) $vorhanden->kind,
        (int) $ziel->id
    );

    exit(0);
}

printf(
    "\n  %s: `Text --%s--> display size` (unter `Integer`), Art `setting`\n",
    $vorhanden === null ? 'anzulegen' : 'umzuhaengen (Kante ' . (int) $vorhanden->id . ')',
    $schluessel
);

if (! $go) {
    echo "\n— Probelauf. Mit --go wird geschrieben. —\n";

    exit(0);
}

// ---------------------------------------------------------------- schreiben

$nodes     = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$editor    = new ModelEditor(
    $nodes,
    $relations,
    new SeededFrameworkNodes($nodes, $relations, $log),
    $log,
    new WpdbLabelRepository(),
    new WpdbRecordRepository()
);

$wpdb->query('START TRANSACTION');

try {
    $zielId = $ziel === null
        ? $editor->createNode('display size', (int) $zahl->id)->id
        : (int) $ziel->id;

    $kante = $vorhanden === null
        ? $editor->addField((int) $text->id, $zielId, $schluessel)
        : $editor->retargetField((int) $text->id, (int) $vorhanden->id, $zielId);

    // ⚠️ **Immer, nicht nur beim Anlegen.** *Das Umhaengen setzt die Art aus dem Zweig des neuen
    // Ziels neu — gemessen stand die Kante danach wieder als `composition` da. **Eine Einstellung,
    // die keine ist, wird im Einstellungsblock nicht gezeichnet**, und der Lauf haette gemeldet,
    // er sei fertig.*
    $editor->markAsSetting((int) $text->id, $kante->id, true);
} catch (\Throwable $e) {
    $wpdb->query('ROLLBACK');

    fwrite(STDERR, 'ROLLBACK: ' . $e->getMessage() . "\n");

    exit(1);
}

$wpdb->query('COMMIT');

printf("\nGeschrieben: Kante %d.\n", $kante->id);
