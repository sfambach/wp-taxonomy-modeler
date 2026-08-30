<?php declare(strict_types=1);
/**
 * Die Rückstände der Randprüfungen wegräumen — die Knoten mit `__` im Namen.
 *
 *     php scripts/clear-check-litter.php            (Probelauf)
 *     php scripts/clear-check-litter.php --write    (raeumt weg)
 *
 * ⚠️ **Auf sein Wort: «entferne die `__***` Sachen mal, die hast du angelegt».** *Und er hat recht —
 * jedes Kürzel ist der Name einer Prüfung: `__uv` von `unitvalue-check`, `__path` von `path-check`,
 * `__ja` von `journal-address-check`, `__sp` und `__cd` von zwei weiteren. **Es sind Vorbereitungen,
 * die nicht aufgeräumt wurden** — meist weil der Lauf vorher abgestürzt ist.*
 *
 * ⚠️ **Der eigentliche Fehler liegt nicht hier, sondern in den Prüfungen.** *Sie räumen nicht
 * zuverlässig auf. Die jüngeren tun es über `register_shutdown_function`, weil das auch bei einem
 * Absturz läuft; die älteren nicht. **Das gehört auf die Arbeitsliste**, sonst steht der Müll morgen
 * wieder da.*
 *
 * ⚠️ *Weggeräumt über den normalen Weg — in den Müll und dann leeren —, damit die Geschichte
 * mitgeschrieben wird ([D-536](../docs/NewConcept/90-decision-log.md)) und nichts unwiederbringlich
 * verschwindet.*
 */

$schreiben = in_array('--write', $argv, true);
$root      = getenv('WP_ROOT') ?: null;

if ($root === null) {
    $dir = getcwd();
    while ($dir !== '' && ! is_readable($dir . '/wp-load.php')) {
        $up  = dirname($dir);
        $dir = $up === $dir ? '' : $up;
    }
    $root = $dir;
}

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, new TableIdentityAllocator(), $log);
$editor    = new ModelEditor($nodes, $edges, new TableIdentityAllocator(), $framework, $log, records: new WpdbRecordRepository());

$muell = $framework->trash();

// ⚠️ *Nur die **obersten** je Teilbaum: `__cd Ast` nimmt `Kind` und `Enkel` mit, und ein zweiter
// Aufruf für ein Kind, das schon im Müll liegt, wäre bestenfalls überflüssig.*
$alle = $wpdb->get_results(
    "SELECT id, name, path FROM " . Schema::table('nodes') . " WHERE name LIKE '\_\_%' ORDER BY path",
    ARRAY_A
) ?: [];

$imMuell = [];
$lebend  = [];

foreach ($alle as $k) {
    if (str_starts_with((string) $k['path'], $muell->path . '.')) {
        $imMuell[] = $k;

        continue;
    }

    $unterAnderem = false;

    foreach ($lebend as $schon) {
        if (str_starts_with((string) $k['path'], $schon['path'] . '.')) {
            $unterAnderem = true;
        }
    }

    if (! $unterAnderem) {
        $lebend[] = $k;
    }
}

printf("%d Knoten mit __ im Namen: %d liegen schon im Muell, %d sind lebend (als %d Teilbaeume)\n\n",
    count($alle), count($imMuell), count($alle) - count($imMuell), count($lebend));

foreach ($lebend as $k) {
    printf("  in den Muell  %-8s %s\n", $k['id'], $k['name']);
}

if (! $schreiben) {
    echo "\nDanach wird der Muell geleert — er enthaelt ausschliesslich diese Rueckstaende.\n";
    echo "Probelauf. Mit --write wegraeumen.\n";
    exit(0);
}

foreach ($lebend as $k) {
    try {
        $editor->moveToTrash((int) $k['id']);
        echo "  weggeraeumt   {$k['id']} {$k['name']}\n";
    } catch (\Throwable $e) {
        echo '  ⚠ ' . $k['id'] . ' ' . $k['name'] . ': ' . $e->getMessage() . "\n";
    }
}

$gone = $editor->clearTrash();

echo "\nMuell geleert: ";

foreach ($gone as $was => $wieviel) {
    echo "{$was} {$wieviel}  ";
}

echo "\n\nNoch mit __ im Namen: "
    . (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::table('nodes') . " WHERE name LIKE '\_\_%'")
    . "\nsettings: " . (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::table('settings')) . "\n";
