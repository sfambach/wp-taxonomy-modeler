<?php declare(strict_types=1);

/**
 * [D-516](../../docs/NewConcept/90-decision-log.md), entschieden am 2026-08-29 und nie gebaut:
 * **`min` und `max` werden Spezialisierungen unter `Integer`, und die Felder zeigen dorthin.**
 *
 * Sein Satz von damals: *«was ist, wenn wir min, max als Spezialisierung an Integer haengen und das
 * Flag ‹ist Setting› setzen — dann, wenn ich in Integer zwei Felder anlege, eins min Typ min und eins
 * max Typ max: waere das besser?»* **Ja, und es ist gemessen statt abgewogen:** *`Integer › min` loest
 * ueber den Vorfahrenlauf zu `int` auf, ohne dass es einen Typ «wie der Besitzer» geben muss.*
 *
 * ⚠️ **Warum es damals nicht kam, steht in [D-516](../../docs/NewConcept/90-decision-log.md) selbst:**
 * *«`Integer › min` erbt `min` mit sich selbst als Ziel … es terminiert; was leidet, ist der Sinn.»*
 * **Mit [D-607](../../docs/NewConcept/90-decision-log.md) faellt dieser Grund weg** — die Sperre trifft
 * genau diese Kante: *«schaedlich sind genau die Kanten, deren Ziel der erbende Knoten selbst ist … die
 * Kante `Integer --min--> min` zeigt auf den Erben, also greift die Sperre.»* **Das Skript prueft die
 * Sperre nach, bevor es etwas verschiebt, und bricht ab, wenn sie fehlt.**
 *
 * ⚠️ *Ausgangslage, gemessen: beide liegen unter `Settings` (`1.40768.41586` und `.41588`), **keine
 * einzige Kante zeigt auf sie**, und keine Kante traegt heute den Namen `min` oder `max`. Es wird also
 * nichts umgehaengt, sondern zwei Felder entstehen, die es nicht gab.*
 *
 * ⚠️ *Probelauf ist die Voreinstellung. Ohne `--go` wird nichts geschrieben.*
 *
 * Aufruf: php scripts/dev/minmax-specialize.php          (nur zeigen)
 *         php scripts/dev/minmax-specialize.php --go     (schreiben, in einer Transaktion)
 *
 * @see docs/NewConcept/90-decision-log.md
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';

define('WP_USE_THEMES', false);

require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\NodeKind;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\ModelValues;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

$go = in_array('--go', $argv, true);

global $wpdb;

$nodesTable = Schema::table('nodes');
$edgesTable = Schema::table('relations');

// ---------------------------------------------------------------- die Sperre nachpruefen

// ⚠️ **Ohne sie entsteht genau die Rekursion, die er beschrieben hat** — *«ein `min`, das ein `min`
// hat, das ein `min` hat»*. Darum ist das die erste Frage und nicht die letzte.
if (! method_exists(ModelValues::class, 'inheritanceBlocked')) {
    fwrite(STDERR, "ABBRUCH: Die Sperre aus D-607 ist nicht gebaut. Ohne sie wird nichts verschoben.\n");

    exit(1);
}

// ---------------------------------------------------------------- messen

$integer = $wpdb->get_row("SELECT id, name, path FROM {$nodesTable} WHERE name = 'Integer'");

if ($integer === null) {
    fwrite(STDERR, "ABBRUCH: Der Knoten `Integer` ist nicht auffindbar.\n");

    exit(1);
}

$ziele = [];

foreach (['min', 'max'] as $name) {
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT id, name, path, kind FROM {$nodesTable} WHERE name = %s AND path LIKE '1.40768.%%'",
        $name
    ));

    if ($row === null) {
        fwrite(STDERR, "ABBRUCH: `{$name}` liegt nicht (mehr) im Settings-Ast.\n");

        exit(1);
    }

    // Was auf ihn zeigt — die Probe aus D-606. Zeigt heute etwas darauf, ist die Lage eine andere
    // als die gemessene, und dann wird nicht verschoben.
    $zeiger = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$edgesTable} WHERE to_id = %d AND kind <> 'inheritance'",
        (int) $row->id
    ));

    if ($zeiger !== 0) {
        fwrite(STDERR, "ABBRUCH: {$zeiger} Kante(n) zeigen auf `{$name}` — nicht die gemessene Lage.\n");

        exit(1);
    }

    $vorhanden = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$edgesTable} WHERE from_id = %d AND name = %s",
        (int) $integer->id,
        $name
    ));

    $ziele[$name] = ['node' => $row, 'feldSchonDa' => $vorhanden > 0];
}

printf("Ziel: `Integer` (%d, %s)\n\n", (int) $integer->id, $integer->path);

foreach ($ziele as $name => $z) {
    printf(
        "  %-4s Knoten %-7d liegt %s, Marke %s\n",
        $name,
        (int) $z['node']->id,
        $z['node']->path,
        $z['node']->kind ?? '(keine)'
    );
    printf("       → zieht unter `Integer`; Marke `setting`; Feld `Integer --%s--> %s`%s\n",
        $name,
        $name,
        $z['feldSchonDa'] ? ' — STEHT SCHON, wird nicht doppelt angelegt' : ''
    );
}

echo "\nDie Sperre aus D-607 ist gebaut — die Kante `Integer --min--> min` zeigt auf den Erben.\n";

// ---------------------------------------------------------------- sichern

$backupDir = getenv('TAXMOD_SCRATCH')
    ?: 'C:/Users/stefan/AppData/Local/Temp/claude/C--Devel-Wordpress-source-wp-taxonomy-tree';

if (! is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}

$backup = $backupDir . '/minmax-' . date('Ymd-His') . '.json';

file_put_contents($backup, json_encode([
    'nodes' => $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$nodesTable} WHERE id IN (%d, %d, %d)",
        (int) $integer->id,
        (int) $ziele['min']['node']->id,
        (int) $ziele['max']['node']->id
    ), ARRAY_A),
    'edges' => $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$edgesTable} WHERE from_id IN (%d, %d, %d) OR to_id IN (%d, %d, %d)",
        (int) $integer->id,
        (int) $ziele['min']['node']->id,
        (int) $ziele['max']['node']->id,
        (int) $integer->id,
        (int) $ziele['min']['node']->id,
        (int) $ziele['max']['node']->id
    ), ARRAY_A),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

printf("\nSicherung: %s\n", $backup);

if (! $go) {
    echo "\n— Probelauf. Mit --go wird geschrieben. —\n";

    exit(0);
}

// ---------------------------------------------------------------- schreiben

$nodes  = new WpdbNodeRepository();
$edges  = new WpdbRelationRepository();
$log    = new WpdbChangelog(new SystemClock());
$editor = new ModelEditor($nodes, $edges, new SeededFrameworkNodes($nodes, $edges, $log), $log);

$wpdb->query('START TRANSACTION');

$failed = null;

try {
    foreach ($ziele as $name => $z) {
        $id = (int) $z['node']->id;

        $editor->move($id, (int) $integer->id);
        $editor->setKind($id, NodeKind::Setting);

        if (! $z['feldSchonDa']) {
            $kante = $editor->addField((int) $integer->id, $id, $name);
            $editor->markAsSetting((int) $integer->id, $kante->id, true);
        }
    }
} catch (\Throwable $e) {
    $failed = $e->getMessage();
}

if ($failed !== null) {
    $wpdb->query('ROLLBACK');

    fwrite(STDERR, "ROLLBACK: {$failed}\n");

    exit(1);
}

$wpdb->query('COMMIT');

echo "\nGeschrieben.\n";

foreach ($ziele as $name => $z) {
    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT path, kind FROM {$nodesTable} WHERE id = %d",
        (int) $z['node']->id
    ));

    printf("  %-4s liegt jetzt %s, Marke %s\n", $name, $row->path, $row->kind ?? '(keine)');
}
