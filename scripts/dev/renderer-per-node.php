<?php declare(strict_types=1);

/**
 * Der Massstab fuer den Rueckbau von `nodes.field_type` — **was jeder Knoten zeichnet**.
 *
 *     php scripts/dev/renderer-per-node.php [path/to/wordpress] > vorher.txt
 *
 * ⚠️ **Eine Wanderung ist gelungen, wenn niemand etwas merkt.** *Sie schreibt je Knoten den
 * aufgeloesten Renderernamen — ueber {@see \Taxmod\Core\Service\Rendering::rendererNameFor()}, also
 * denselben Weg, den die Oberflaeche geht. Vorher und nachher verglichen: **weicht eine Zeile ab,
 * bricht die Wanderung ab** ([D-621](../../docs/NewConcept/90-decision-log.md)).*
 *
 * @see docs/NewConcept/30-renderer.md
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

use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelValues;
use Taxmod\Core\Service\Rendering;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;

$nodes     = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$model     = new ModelValues(new WpdbRecordRepository(), $relations, $nodes, $framework);

$rendering = new Rendering(
    $nodes,
    $framework,
    ShippedRenderers::registry(),
    new SeededTypeNodes($nodes, $framework),
    new Labels(new WpdbLabelRepository(), SettingsScreen::neutralLocale()),
    null,
    $model,
    $relations
);

$ids = $wpdb->get_col('SELECT id FROM ' . Schema::table('nodes') . ' ORDER BY id');
$mit = 0;

foreach ($ids as $id) {
    $node = $nodes->find((int) $id);

    if ($node === null) {
        continue;
    }

    $name = $rendering->rendererNameFor($node);
    $mit += $name === null ? 0 : 1;

    printf("%6d  %-30s %s\n", $node->id, $node->name, $name ?? '-');
}

printf("# %d von %d Knoten zeichnen mit einem benannten Renderer\n", $mit, count($ids));
