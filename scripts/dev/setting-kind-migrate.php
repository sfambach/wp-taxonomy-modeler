<?php declare(strict_types=1);

/**
 * Die Wanderung aus [D-606](../../docs/NewConcept/90-decision-log.md) (TASK-041): die Marke
 * `nodes.kind = 'setting'` wird einmalig aus dem Ast `Settings` gefuellt.
 *
 * ⚠️ **Warum die Marke und nicht der Ast** ([D-606](../../docs/NewConcept/90-decision-log.md)):
 * *sein eigener Fall zeigt es — `read_only` liegt unter `Boolean`, **weil es ein Boolean ist**. Wer
 * einen Einstellungsknoten dorthin schiebt, wo er inhaltlich hingehoert, **nimmt die Marke mit; den
 * Ast nicht.*** Darum bekommt **jeder** Knoten seine eigene Marke, statt dass nur die Astwurzel
 * markiert wird und der Vorfahrenlauf den Rest erledigt: eine geerbte Marke bliebe beim Umzug
 * zurueck.
 *
 * ⚠️ **`min` und `max` sind ausgenommen, und das steht in der Entscheidung selbst:** *«sie liegen im
 * Ast, tragen nichts, **und keine einzige Kante zeigt auf sie** — beim Fuellen wird auffallen, dass
 * sie keine Einstellung mehr sind, sondern Rest.»* Sie gehoeren nach
 * [D-516](../../docs/NewConcept/90-decision-log.md) als Spezialisierungen unter `Integer`, nicht in
 * diesen Ast. Das Skript markiert sie nicht — es zaehlt sie und benennt sie.
 *
 * ⚠️ *Die Astwurzel `Settings` selbst bleibt ebenfalls unmarkiert: sie ist der Ort, nicht die Sache.
 * [D-606](../../docs/NewConcept/90-decision-log.md) zaehlt «38 Knoten im Settings-Ast» — das sind die
 * Knoten **unter** ihr.*
 *
 * ⚠️ *Probelauf ist die Voreinstellung. Ohne `--go` wird nichts geschrieben — dasselbe Muster wie
 * {@see displayoption-migrate.php}, aus demselben Grund.*
 *
 * Aufruf: php scripts/dev/setting-kind-migrate.php          (nur zaehlen)
 *         php scripts/dev/setting-kind-migrate.php --go     (schreiben, in einer Transaktion)
 *
 * @see docs/NewConcept/90-decision-log.md
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';

define('WP_USE_THEMES', false);

require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\FieldType;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

$go = in_array('--go', $argv, true);

global $wpdb;

$nodesTable = Schema::table('nodes');

// ---------------------------------------------------------------- messen

$branch = $wpdb->get_row(
    "SELECT id, path FROM {$nodesTable} WHERE name = 'Settings' AND path NOT LIKE '%.%.%'"
);

if ($branch === null) {
    fwrite(STDERR, "ABBRUCH: Die Astwurzel `Settings` ist nicht auffindbar.\n");

    exit(1);
}

$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT id, name, path, field_type FROM {$nodesTable} WHERE path LIKE %s ORDER BY path",
    $wpdb->esc_like($branch->path . '.') . '%'
), ARRAY_A);

// ⚠️ **Die Ausnahme ist gemessen und nicht gesetzt, aber sie gilt nur eine Ebene tief.** *Die
// **direkten** Kinder der Astwurzel sind die Einstellungen selbst — `Renderer`, `Converter`,
// `Validator`, `Orientation`, `Label roles`; jedes ist Ziel einer Einstellungskante. Was eine Ebene
// tiefer liegt (`checkbox`, `roman`, `horizontal`) ist ein **Wert** einer Einstellung und ist nie
// Kantenziel — die Frage «zeigt eine Kante darauf» darf also nur den direkten Kindern gestellt
// werden, sonst faellt der halbe Ast heraus.*
//
// ⚠️ *Genau diese Messung trennt `min` und `max` von ihren Geschwistern
// ([D-606](../../docs/NewConcept/90-decision-log.md)): sie stehen auf derselben Ebene und **keine
// einzige Kante zeigt auf sie**.*
$restless = [];

$depth = substr_count($branch->path, '.') + 2;

foreach ($rows as $row) {
    if (substr_count((string) $row['path'], '.') + 1 !== $depth) {
        continue;
    }

    $incoming = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . Schema::table('relations') . " WHERE to_id = %d AND kind <> 'inheritance'",
        (int) $row['id']
    ));

    // ⚠️ *Nicht Ziel zu sein genuegt nicht: `Renderer` ist ebenfalls kein Kantenziel, **traegt aber
    // die Einstellung `converter` und alle Renderer als Kinder**. Rest ist, was weder jemandem
    // dient noch etwas haelt.*
    $outgoing = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT COUNT(*) FROM ' . Schema::table('relations') . ' WHERE from_id = %d',
        (int) $row['id']
    ));

    if ($incoming === 0 && $outgoing === 0) {
        $restless[(int) $row['id']] = $row['name'];
    }
}

$toMark  = [];
$already = [];
$foreign = [];

foreach ($rows as $row) {
    $id   = (int) $row['id'];
    $kind = $row['field_type'];

    if ($kind !== null && $kind !== '') {
        if ($kind === FieldType::Setting->value) {
            $already[$id] = $row['name'];
        } else {
            $foreign[$id] = $row['name'] . ' (' . $kind . ')';
        }

        continue;
    }

    if (isset($restless[$id])) {
        continue;
    }

    $toMark[$id] = $row['name'];
}

printf("Astwurzel `Settings`: Knoten %d, Pfad %s\n", (int) $branch->id, $branch->path);
printf("Knoten im Ast (ohne die Wurzel): %d\n\n", count($rows));

printf("Schon markiert: %d\n", count($already));

foreach ($already as $id => $name) {
    printf("   %-8d %s\n", $id, $name);
}

printf("\nOhne eingehende Kante ausser Vererbung — nicht markiert, siehe D-606: %d\n", count($restless));

foreach ($restless as $id => $name) {
    printf(
        "   %-8d %-20s %s\n",
        $id,
        $name,
        isset($already[$id]) ? '⚠ traegt die Marke schon — bleibt stehen, nichts wird geloescht' : ''
    );
}

if ($foreign !== []) {
    printf("\nMit einer anderen Marke — unangetastet: %d\n", count($foreign));

    foreach ($foreign as $id => $name) {
        printf("   %-8d %s\n", $id, $name);
    }
}

printf("\nBekommen die Marke `setting`: %d\n", count($toMark));

foreach ($toMark as $id => $name) {
    printf("   %-8d %s\n", $id, $name);
}

// Ein Rest, der die Marke schon traegt, steht in beiden Listen und darf nur einmal zaehlen.
$doppelt = count(array_intersect_key($restless, $already));
$total   = count($already) + count($restless) - $doppelt + count($foreign) + count($toMark);

if ($total !== count($rows)) {
    fwrite(STDERR, "ABBRUCH: {$total} eingeordnet, aber " . count($rows) . " im Ast.\n");

    exit(1);
}

// ---------------------------------------------------------------- sichern

$backupDir = getenv('TAXMOD_SCRATCH')
    ?: 'C:/Users/stefan/AppData/Local/Temp/claude/C--Devel-Wordpress-source-wp-taxonomy-tree';

if (! is_dir($backupDir)) {
    mkdir($backupDir, 0777, true);
}

$backup = $backupDir . '/nodes-kind-' . date('Ymd-His') . '.json';

file_put_contents($backup, json_encode(
    $wpdb->get_results("SELECT id, name, kind, version FROM {$nodesTable}", ARRAY_A),
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
));

printf("\nSicherung aller Marken: %s\n", $backup);

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

foreach ($toMark as $id => $name) {
    try {
        $editor->setFieldType($id, FieldType::Setting);
    } catch (\Throwable $e) {
        $failed = "Knoten {$id} ({$name}): " . $e->getMessage();

        break;
    }
}

if ($failed !== null) {
    $wpdb->query('ROLLBACK');

    fwrite(STDERR, "ROLLBACK: {$failed}\n");

    exit(1);
}

$wpdb->query('COMMIT');

$marked = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$nodesTable} WHERE path LIKE %s AND field_type = 'setting'",
    $wpdb->esc_like($branch->path . '.') . '%'
));

printf("\nGeschrieben. Markiert im Ast jetzt: %d von %d.\n", $marked, count($rows));
