<?php declare(strict_types=1);

/**
 * Give every node and every attribute that already exists its **own** settings rows.
 *
 * [D-423](../../docs/NewConcept/90-decision-log.md) materialises settings **on creation** — which
 * leaves the tree that was built before it resolving through the chain, so one model would carry two
 * behaviours. This is the one-time catch-up, and the owner asked for it in that order: *yes, back up
 * first.*
 *
 * ⚠️ **It writes a backup before it writes anything else, and refuses to run without one.** The two
 * tables it can touch are `settings` (the rows) and `changelog` (because every setting write journals
 * itself, [D-403](../../docs/NewConcept/90-decision-log.md)). *A dev script that doubles a table is
 * exactly the kind that should not be undoable only in principle.*
 *
 * ⚠️ **Order does not matter, and that is a property of resolving rather than luck.**
 * {@see \Taxmod\Core\Service\Settings::materialise()} walks the chain, so a child materialises to the
 * value **in force** whether or not its parent has been done yet. *Copying rows instead would have
 * made this a topological sort.*
 *
 * ⚠️ **Nothing is ever written over.** `materialise()` skips a key the owner already holds, so this is
 * safe to run twice and cannot undo an edit — which is also why it can be run again after the next
 * node is added by hand.
 *
 * Usage: php scripts/dev/materialise-backfill.php            (dry run — counts only)
 *        php scripts/dev/materialise-backfill.php --go       (back up, then write)
 *
 * @see docs/NewConcept/90-decision-log.md
 */

$wordpress = 'C:/Devel/Wordpress';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--wp=')) {
        $wordpress = substr($arg, 5);
    }
}

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingRepository;
use Taxmod\WordPress\SystemClock;

$go = in_array('--go', $argv, true);

$ids       = new TableIdentityAllocator();
$log       = new WpdbChangelog(new SystemClock());
$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($nodes, $edges, $ids, $log);
$settings  = new Settings(new WpdbSettingRepository(), $nodes, $framework, $log);

global $wpdb;

$p = $wpdb->prefix . 'taxmod_';

/** Every row of a table as INSERT statements, so a restore needs nothing but a MySQL client. */
function dumpTable(string $table): string
{
    global $wpdb;

    $rows = $wpdb->get_results("SELECT * FROM {$table}", ARRAY_A);

    if ($wpdb->last_error !== '') {
        throw new RuntimeException("Dump von {$table} fehlgeschlagen: " . $wpdb->last_error);
    }

    $out = "-- {$table}: " . count($rows) . " Zeilen\n";

    foreach ($rows as $row) {
        $cols   = [];
        $values = [];

        foreach ($row as $col => $value) {
            $cols[]   = '`' . $col . '`';
            $values[] = $value === null ? 'NULL' : "'" . esc_sql((string) $value) . "'";
        }

        $out .= 'INSERT INTO `' . $table . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $values) . ");\n";
    }

    return $out;
}

// ⚠️ **The scratch nodes my own boundary checks leave behind are left out**, by the `__` convention
// they all use. *Furnishing them with fifteen rows each would add to the 720 orphaned rows list row 28
// is already about — and every one of them is deleted again by the next run of the check that made it.*
$nodeIds = array_map('intval', $wpdb->get_col("SELECT id FROM {$p}nodes WHERE name NOT LIKE '\\_\\_%'"));

// ⚠️ **One query, not one per node** (`CD-7`). *The first version of this loop asked
// `attributeEdgesOf()` inside the loop to find one edge by id — the N+1 the code standard forbids,
// written in a file whose whole purpose is to touch every row once.*
$attributes = [];

foreach ($edges->attributeEdgesOf($nodeIds) as $one) {
    $attributes[] = $one;
}

if ($wpdb->last_error !== '') {
    echo 'DB-FEHLER: ', $wpdb->last_error, "\n";

    exit(1);
}

$before = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}settings");

printf("Knoten %d, Attributkanten %d, Settings-Zeilen jetzt %d\n\n", count($nodeIds), count($attributes), $before);

if (! $go) {
    echo "— Probelauf. Mit --go wird gesichert und dann geschrieben. —\n";

    exit(0);
}

// ── The backup, first and unconditionally. ──
$stamp = $wpdb->get_var('SELECT DATE_FORMAT(NOW(), "%Y%m%d-%H%i%s")');
$file  = __DIR__ . '/../../.backup-settings-' . $stamp . '.sql';

$dump = "-- taxmod backfill backup, " . $stamp . "\n"
    . "-- Wiederherstellen: die beiden Tabellen leeren, dann diese Datei einspielen.\n\n"
    . dumpTable($p . 'settings')
    . "\n"
    . dumpTable($p . 'changelog');

if (file_put_contents($file, $dump) === false) {
    echo "Sicherung konnte nicht geschrieben werden — nichts geaendert.\n";

    exit(1);
}

printf("Sicherung: %s (%d KB)\n\n", $file, (int) (strlen($dump) / 1024));

// ── The catch-up. ──
$touched = 0;
$written = 0;

foreach ($nodeIds as $id) {
    $node   = $nodes->byId($id);
    $parent = $node->parentId();

    if ($parent === 0 || $parent === null) {
        // A root has nothing above it; the installation identity is not a node at all.
        continue;
    }

    $keys = $settings->materialise($settings->chainFor($nodes->byId($parent)), $settings->chainFor($node));

    $touched++;
    $written += count($keys);
}

foreach ($attributes as $edge) {
    $keys = $settings->materialise($settings->chainFor($nodes->byId($edge->toId)), $settings->chainForUseSite($edge));

    $touched++;
    $written += count($keys);
}

$after = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}settings");

printf("Besitzer bearbeitet: %d\nZeilen geschrieben:  %d\n", $touched, $written);
printf("Settings-Zeilen: %d → %d\n", $before, $after);
