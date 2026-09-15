<?php declare(strict_types=1);

/**
 * Jedem bestehenden Knoten seinen Renderer als eigene Zeile geben ([D-814](../../docs/NewConcept/90-decision-log.md)).
 *
 *     php scripts/dev/renderer-backfill.php            # nur zeigen, was geschrieben würde
 *     php scripts/dev/renderer-backfill.php --write    # schreiben
 *
 * ⚠️ **Eine Wanderung ist gelungen, wenn niemand etwas merkt** (D-621). *Geschrieben wird je Knoten der Renderer, der heute an ihm
 * gilt ({@see \Taxmod\Core\Service\Rendering::rendererNameFor()}, derselbe Weg wie die Oberfläche); wo keiner gilt, der für einen neuen
 * Knoten (D-808). Vorher und nachher wird verglichen, und jede Abweichung wird genannt.*
 *
 * ⚠️ *Sein Wort: «yes force one for every node».*
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';

if (! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php. Set WP_ROOT to the WordPress folder.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Node;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();

/** @var \Taxmod\Core\Service\Rendering $rendering */
$rendering = (new ReflectionProperty($screen, 'rendering'))->getValue($screen);
/** @var \Taxmod\Core\Service\SettingsEditor $attributes */
$attributes = (new ReflectionProperty($screen, 'attributes'))->getValue($screen);
/** @var \Taxmod\Core\Service\ModelEditor $editor */
$editor = (new ReflectionProperty($screen, 'editor'))->getValue($screen);

global $wpdb;
$p   = $wpdb->prefix . 'taxmod_';
$ids = array_map('intval', $wpdb->get_col(
    "SELECT n.id FROM {$p}nodes n WHERE NOT EXISTS (SELECT 1 FROM {$p}settings_value v WHERE v.node_id = n.id AND v.attribut = 'renderer' AND v.relation_id IS NULL AND v.settings_object_id IS NULL) ORDER BY n.id"
));

$vorher   = [];
$geplant  = [];
$ohne     = 0;

foreach ($ids as $id) {
    $knoten = $editor->find($id);

    if (! $knoten instanceof Node || ! $attributes->knows($knoten, 'renderer')) {
        ++$ohne;
        continue;
    }

    $gilt          = $rendering->rendererNameFor($knoten, Purpose::Display);
    $vorher[$id]   = $gilt;
    $geplant[$id]  = $gilt !== null && $rendering->knowsRenderer($gilt)
        ? $gilt
        : $rendering->rendererForNewNode($knoten, $knoten->parentNodeId === null ? null : $editor->find($knoten->parentNodeId));
}

printf("%d Knoten ohne eigene Renderer-Zeile, %d kennen «renderer» nicht.\n", count($ids), $ohne);

foreach (array_count_values($geplant) as $name => $anzahl) {
    printf("  %-12s %d\n", $name, $anzahl);
}

if (! $schreiben) {
    echo "Nichts geschrieben (ohne --write).\n";
    exit(0);
}

$geschrieben = 0;

foreach ($geplant as $id => $name) {
    if ($attributes->put($editor->find($id), 'renderer', $name, null, true)) {
        ++$geschrieben;
    }
}

$abweichend = [];

foreach ($vorher as $id => $gilt) {
    $jetzt = $rendering->rendererNameFor($editor->find($id), Purpose::Display);

    if ($gilt !== null && $jetzt !== $gilt) {
        $abweichend[] = sprintf('#%d %s → %s', $id, $gilt, (string) $jetzt);
    }
}

printf("%d Zeilen geschrieben; %d Knoten zeichnen jetzt anders.\n", $geschrieben, count($abweichend));

foreach (array_slice($abweichend, 0, 30) as $zeile) {
    echo '  ', $zeile, "\n";
}

exit($abweichend === [] ? 0 : 1);
