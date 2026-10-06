<?php declare(strict_types=1);
/**
 * Der Baum bleibt flacher als die Kette, mit der {@see \Taxmod\WordPress\Persistence\Ancestry} aufsteigt (D-910).
 *
 *     php scripts/dev/ancestry-depth-check.php [path/to/wordpress]
 *
 * ```mermaid
 * flowchart LR
 *   P["parent_node_id, in PHP abgestiegen"] --> T["tiefste Ebene"]
 *   T --> G{"Abstand zur Grenze ≥ 4?"}
 * ```
 *
 * ⚠️ *Ein Knoten tiefer als die Grenze verschwände still aus jedem Leser. Gezählt wird hier unabhängig von SQL —
 * mit dem Abstieg in PHP —, damit der Wächter nicht auf dem steht, was er prüft.*
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

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — siehe `lib/no-write.php`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\WordPress\Persistence\Ancestry;
use Taxmod\WordPress\Persistence\Query;
use Taxmod\WordPress\Persistence\Schema;

$kinder = [];

foreach (Query::rows('Väter lesen', 'SELECT id, parent_node_id FROM ' . Schema::table('nodes')) as $zeile) {
    $kinder[(int) ($zeile['parent_node_id'] ?? 0)][] = (int) $zeile['id'];
}

$tiefste = -1;
$ebene   = $kinder[0] ?? [];

while ($ebene !== []) {
    $tiefste++;
    $naechste = [];

    foreach ($ebene as $id) {
        array_push($naechste, ...($kinder[$id] ?? []));
    }

    $ebene = $naechste;
}

$grenze = Ancestry::MAX_DEPTH;
$ok     = $tiefste <= $grenze - 4;

echo ($ok ? '  OK   ' : '  FAIL ') . "tiefste Ebene {$tiefste}, Grenze {$grenze}\n";

exit($ok ? 0 : 1);
