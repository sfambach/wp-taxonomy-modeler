<?php declare(strict_types=1);

/**
 * Ein Link bekommt eine Beschriftung ([D-855](../../docs/NewConcept/90-decision-log.md)) — *sein Wort: «links sollen eine beschriftung
 * haben».*
 *
 *     php scripts/dev/medium-beschriftung.php            # nur zeigen
 *     php scripts/dev/medium-beschriftung.php --write    # umbauen
 *
 * *Gebaut wie der Einheitenwert: ein zusammengesetzter Knoten `Medium` unter `Combined` mit **Adresse** und **Beschriftung**. Jedes
 * mehrfache Medienfeld wird ein Teil davon; die vorhandenen Adressen wandern mit, die Beschriftung bleibt leer — dann gilt weiter die aus
 * der Datei gerechnete ([D-846](../../docs/NewConcept/90-decision-log.md)).*
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\NodeClass\Category;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
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
$data    = (new ReflectionProperty($screen, 'data'))->getValue($screen);
$records = new WpdbRecordRepository();

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

const COMBINED = 3984, MEDIA = 149000105224, TEXT = 1175;

$sagen = static function (string $s): void { echo $s, "\n"; };

// ── Der Knoten «Medium»: Adresse und Beschriftung ──────────────────────────────────────────────
$medium = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$p}nodes_named WHERE name = %s AND parent_node_id = %d", 'Medium', COMBINED));

if ($medium === null) {
    $sagen('Knoten «Medium» unter Combined');
    $medium = $schreiben ? $editor->createNode('Medium', COMBINED, Category::class)->id : null;
} else {
    $medium = (int) $medium;
}

$feld = static function (?int $eigner, int $ziel, string $name, Multiplicity $wieOft) use ($editor, $wpdb, $p, $schreiben, $sagen): ?int {
    if ($eigner === null) {
        return null;
    }

    $steht = $wpdb->get_var($wpdb->prepare(
        "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s",
        $eigner,
        $name
    ));

    if ($steht !== null) {
        return (int) $steht;
    }

    $sagen("Feld «{$name}» an {$eigner} ({$wieOft->value})");

    if (! $schreiben) {
        return null;
    }

    $kante = $editor->addField($eigner, $ziel, $name, RelationKind::Composition);

    return $kante->multiplicity === $wieOft ? $kante->id : $editor->setMultiplicity($eigner, $kante->id, $wieOft)->id;
};

$adresse      = $feld($medium, MEDIA, 'Adresse', Multiplicity::ExactlyOne);
$beschriftung = $feld($medium, TEXT, 'Beschriftung', Multiplicity::ZeroToOne);

// ── Jedes mehrfache Medienfeld wird ein Teil «Medium» ──────────────────────────────────────────
$felder = $wpdb->get_results($wpdb->prepare(
    "SELECT r.id, r.from_node_id, n.name, f.name AS eigner FROM {$p}relations r
     JOIN {$p}relations_named n ON n.id = r.id JOIN {$p}nodes_named f ON f.id = r.from_node_id
     WHERE r.to_node_id = %d AND r.multiplicity = %s",
    MEDIA,
    '0..*'
));

foreach ($felder as $alt) {
    $werte = $wpdb->get_results($wpdb->prepare(
        "SELECT id, node_record_id, value_text, position FROM {$p}relation_records WHERE relation_id = %d ORDER BY node_record_id, position, id",
        (int) $alt->id
    ));

    $sagen("\n── {$alt->eigner} › {$alt->name}: " . count($werte) . ' Adresse(n)');

    if (! $schreiben) {
        continue;
    }

    // *Erst das neue Feld, dann die Werte hinüber, dann das alte weg — in dieser Reihenfolge geht nichts verloren.*
    $neu = $editor->addField((int) $alt->from_node_id, (int) $medium, $alt->name . ' (neu)', RelationKind::Composition);
    $neu = $editor->setMultiplicity((int) $alt->from_node_id, $neu->id, Multiplicity::ZeroToMany);

    foreach ($werte as $wert) {
        $teil = $data->createPart((int) $wert->node_record_id, $neu->id);
        $data->put($teil->id, (int) $adresse, TypedValue::ofText((string) $wert->value_text));
        $sagen('   ' . $wert->value_text . ' → Satz ' . $wert->node_record_id);
        $records->forgetValueById((int) $wert->id);
    }

    $editor->removeField((int) $alt->from_node_id, (int) $alt->id);
    $editor->renameField((int) $alt->from_node_id, $neu->id, $alt->name);
    $sagen("   Feld «{$alt->name}» zeigt jetzt auf Medium");
}

echo "\n", $schreiben ? "geschrieben\n" : "trocken — nichts geschrieben\n";
