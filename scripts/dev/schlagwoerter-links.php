<?php declare(strict_types=1);

/**
 * Schlagwörter am Link — Gruppen von Links ohne eigenen Bau (2026-09-20).
 *
 *     php scripts/dev/schlagwoerter-links.php            # nur zeigen
 *     php scripts/dev/schlagwoerter-links.php --write    # anlegen
 *
 * ⚠️ *Seine Worte ([D-892](../../docs/NewConcept/90-decision-log.md)): «bei den link gruppen bin ich mir unsicher, könnte auch
 * sowas wie tags sein, wir selektieren dann über die tags die gruppe, und wenn wir auf der website die gruppe brauchen, nehmen
 * wir auch die tags» — auf die Unterscheidung (eine Serie ist eines und trägt eigene Angaben, ein Schlagwort ist keines und
 * trägt nur seinen Namen): «ok ergibt sinn».*
 *
 * *Nichts Neues im Bau: ein Schlagwort ist eine Konstante, das Feld ein mehrfacher Verweis — dieselbe Form wie «Herkunft» oder
 * «Erweiterungen». Am «Medium» (Adresse + Beschriftung, D-855) hängt es, also an jedem Link, gleich an welchem Ding er steht.*
 *
 * Wiederholbar: Knoten, Konstanten und Feld werden am Namen erkannt.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\NodeClass\Choice;
use Taxmod\Core\Model\NodeClass\Constant;
use Taxmod\Core\Model\RelationKind;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
/** @var \Taxmod\Core\Service\ModelEditor $editor */
$editor = (new ReflectionProperty($screen, 'editor'))->getValue($screen);

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

// Gemessen am 2026-09-20
const KONSTANTEN = 410, MEDIUM = 149000107775;

/** Seine drei Beispiele — mehr trägt er selbst ein. */
$woerter = ['Retro-Webshop', 'Retro-Software', 'Retro-Forum'];

$knoten = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = %d", 'Schlagwörter', KONSTANTEN));

if ($knoten === null) {
    echo "Auswahl «Schlagwörter» unter den Konstanten\n";
    $knoten = $schreiben ? $editor->createNode('Schlagwörter', KONSTANTEN, Choice::class)->id : null;
}

foreach ($woerter as $wort) {
    if ($knoten === null) {
        echo "  Konstante «{$wort}»\n";
        continue;
    }

    $steht = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = %d", $wort, $knoten));

    if ($steht !== null) {
        continue;
    }

    echo "  Konstante «{$wort}»\n";

    if ($schreiben) {
        $editor->createNode($wort, (int) $knoten, Constant::class);
    }
}

$feld = $wpdb->get_var($wpdb->prepare(
    "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s",
    MEDIUM,
    'Schlagwörter'
));

if ($feld === null && $knoten !== null) {
    echo "Feld «Schlagwörter» am Medium (0..*)\n";

    if ($schreiben) {
        $kante = $editor->addField(MEDIUM, (int) $knoten, 'Schlagwörter', RelationKind::Aggregation);
        $editor->setMultiplicity(MEDIUM, $kante->id, Multiplicity::ZeroToMany);
    }
}

echo $schreiben ? "fertig\n" : "— nur gezeigt. Mit --write anlegen.\n";
