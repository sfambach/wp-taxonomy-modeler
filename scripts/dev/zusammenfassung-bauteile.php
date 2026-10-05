<?php declare(strict_types=1);

/**
 * Das erste Feld vom Typ «Zusammenfassung»: an «Electronic Parts» (2026-09-20).
 *
 *     php scripts/dev/zusammenfassung-bauteile.php            # nur zeigen
 *     php scripts/dev/zusammenfassung-bauteile.php --write    # anlegen und alle Sätze nachrechnen
 *
 * ⚠️ *Sein Wort ([D-885](../../docs/NewConcept/90-decision-log.md)): «bei Bauteilen ist es eigentlich die zusammengesetzte
 * Geschichte … warum speichern wir nicht das Zusammengesetzte in einem Feld, und dann haben wir es auch einfacher mit der
 * Selektion».*
 *
 * *Welche Felder zusammengefasst werden, sagt hier niemand neu: ohne Wahl am Feld gilt `summary_fields` des Knotens, den
 * der Satz trägt — beim Widerstand also Widerstandswert, Toleranz, Bauform («330 Ω · ±20 % · 0207»).*
 *
 * Wiederholbar: das Feld wird nur angelegt, wenn es fehlt; nachgerechnet wird immer.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\SimpleType;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
/** @var \Taxmod\Core\Service\ModelEditor $editor */
$editor = (new ReflectionProperty($screen, 'editor'))->getValue($screen);
/** @var \Taxmod\Core\Service\DataEntry $data */
$data = (new ReflectionProperty($screen, 'data'))->getValue($screen);
/** @var \Taxmod\Core\Service\Rendering $rendering */
$rendering = (new ReflectionProperty($screen, 'rendering'))->getValue($screen);

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

// Gemessen am 2026-09-20
const ELECTRONIC_PARTS = 3634;

$typNodes  = (new ReflectionMethod($plugin, "typeNodes"))->invoke($plugin);
$typKnoten = $typNodes->nodeId(SimpleType::Summary);

if ($typKnoten === null) {
    exit("Den Typknoten «Zusammenfassung» gibt es noch nicht — erst die Saat laufen lassen.\n");
}

$kante = $wpdb->get_var($wpdb->prepare(
    "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s",
    ELECTRONIC_PARTS,
    'Zusammenfassung'
));

if ($kante === null) {
    echo "Feld «Zusammenfassung» an Electronic Parts (0..1)\n";

    if ($schreiben) {
        $neu   = $editor->addField(ELECTRONIC_PARTS, $typKnoten, 'Zusammenfassung', RelationKind::Composition);
        $kante = $editor->setMultiplicity(ELECTRONIC_PARTS, $neu->id, Multiplicity::ZeroToOne)->id;
    }
}

if (! $schreiben) {
    exit("— nur gezeigt. Mit --write anlegen und nachrechnen.\n");
}

// *Alle Sätze unter «Electronic Parts» nachrechnen — der Nachlauf tut es sonst erst bei der nächsten Änderung.*
$saetze = array_map('intval', $wpdb->get_col($wpdb->prepare(
    "WITH RECURSIVE ast(id) AS (SELECT %d UNION ALL SELECT n.id FROM {$p}nodes n JOIN ast ON n.parent_node_id = ast.id)
     SELECT r.id FROM {$p}node_records r JOIN ast ON ast.id = r.node_id WHERE r.record_type = 'user'",
    ELECTRONIC_PARTS
)));

$schreiber = new \Taxmod\Core\Service\SummaryWriter(
    new \Taxmod\WordPress\Persistence\WpdbRecordRepository(),
    new \Taxmod\WordPress\Persistence\WpdbRelationRepository(),
    new \Taxmod\WordPress\Persistence\WpdbNodeRepository(),
    (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin),
    $typNodes,
    $rendering,
    $data
);

echo count($saetze) . " Sätze, geändert: " . $schreiber->refresh($saetze) . "\n";

foreach (array_slice($saetze, 0, 8) as $satz) {
    $text = $wpdb->get_var($wpdb->prepare(
        "SELECT value_text FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d",
        $satz,
        (int) $kante
    ));
    echo "  #{$satz}: " . ($text ?? '—') . "\n";
}
