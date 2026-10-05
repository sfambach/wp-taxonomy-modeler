<?php declare(strict_types=1);

/**
 * Die Bezeichnung der übrigen Bauteilarten — ICs, Verbinder, Kabel, Module (2026-09-20).
 *
 *     php scripts/dev/bezeichnung-weitere-bauteile.php            # nur zeigen
 *     php scripts/dev/bezeichnung-weitere-bauteile.php --write    # eintragen und nachrechnen
 *
 * ⚠️ *Sein Wort ([D-888](../../docs/NewConcept/90-decision-log.md)): «ja mach das für ics, steckverbinder, kabel und module».*
 *
 * *Je Bauteilart dieselbe Liste an zwei Stellen, und sie sagen Verschiedenes: **am Knoten** steht, was ein Wähler zeigt,
 * **an der Kante «Bezeichnung»** steht, woraus sich das Feld hier zusammensetzt. Gerechnet und geschrieben wird danach einmal;
 * weiter hält der Nachlauf sie aktuell. Keine dieser Bezeichnungen war von Hand gefüllt — gemessen am 2026-09-20.*
 *
 * Wiederholbar: was schon steht, bleibt.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Setting\SettingsValue;
use Taxmod\Core\Model\TypedValue;
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
/** @var \Taxmod\Core\Service\SettingsEditor $attributes */
$attributes = (new ReflectionProperty($screen, 'attributes'))->getValue($screen);
$store      = (new ReflectionProperty($attributes, 'settings'))->getValue($attributes);

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

// Gemessen am 2026-09-20
const ELECTRONIC_PARTS = 3634, BEZEICHNUNG = 149000103839;
const TYP = 149000105278, BAUFORM = 149000104307, HERSTELLER = 149000108565, SERIE = 149000108566, TEILENUMMER = 149000108567;
const PINZAHL = 149000104327, LOGIKFAMILIE = 149000104328, SPEICHERART = 149000104329, SPEICHERGROESSE = 149000104330;
const POLZAHL = 149000104333, RASTER = 149000104335, STECKERTYP = 149000104337, GESCHLECHT = 149000104338, MODUL_TYP = 149000105279;

/** Knoten ⇒ die Felder, aus denen sich seine Bezeichnung zusammensetzt. */
$listen = [
    149000104413 => [TYP, BAUFORM, PINZAHL],                             // IC
    149000104414 => [TYP, LOGIKFAMILIE, BAUFORM],                        // Logik-IC
    149000104415 => [TYP, SPEICHERART, SPEICHERGROESSE, BAUFORM],        // Speicher-IC
    149000104412 => [TYP, BAUFORM],                                      // Transistor
    149000104418 => [SERIE, POLZAHL, RASTER],                            // Verbinder — gilt auch für Stift-, Buchsenleiste, Sockel, Jumper
    149000104423 => [SERIE, STECKERTYP, POLZAHL, GESCHLECHT],            // Steckverbinder
    149000104432 => [HERSTELLER, TEILENUMMER],                           // Kabel
    149000104428 => [MODUL_TYP, HERSTELLER],                             // Module
];

$klasseFuer = static function (int $knotenId) use ($wpdb, $p): string {
    // ⚠️ *Die Klasse der Zeile **dieses Knotens** — gemessen: eine beliebige Zeile brachte `Choice` statt `Category`, und die
    // Auflösung sah die Kantenzeile dann nicht an.*
    $klasse = $wpdb->get_var($wpdb->prepare("SELECT klasse FROM {$p}settings_value WHERE attribut = 'summary_fields' AND node_id = %d AND relation_id IS NULL LIMIT 1", $knotenId));

    return (string) $klasse;
};

foreach ($listen as $knotenId => $felder) {
    $knoten = $editor->find($knotenId);

    if ($knoten === null) {
        echo "Knoten {$knotenId} gibt es nicht\n";
        continue;
    }

    $namen = [];

    foreach ($felder as $feldId) {
        $namen[] = $editor->relationById($feldId)?->name ?? ('#' . $feldId);
    }

    $stehtAmKnoten = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}settings_value WHERE attribut = 'summary_fields' AND node_id = %d AND relation_id IS NULL AND aktiv = 1",
        $knotenId
    ));
    $stehtAnKante  = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}settings_value WHERE attribut = 'summary_fields' AND node_id = %d AND relation_id = %d AND aktiv = 1",
        $knotenId,
        BEZEICHNUNG
    ));

    echo "«{$knoten->name}»: " . implode(' · ', $namen)
        . ($stehtAmKnoten > 0 ? ' (Knoten steht)' : '') . ($stehtAnKante > 0 ? ' (Kante steht)' : '') . "\n";

    if (! $schreiben) {
        continue;
    }

    if ($stehtAmKnoten === 0) {
        $attributes->setMembers($knoten, 'summary_fields', $felder);
    }

    if ($stehtAnKante === 0) {
        foreach ($felder as $stelle => $feldId) {
            $store->addValue(SettingsValue::atNode($knotenId, $klasseFuer($knotenId), 'summary_fields', TypedValue::ofRelationReference($feldId), BEZEICHNUNG, $stelle));
        }
    }
}

if (! $schreiben) {
    exit("— nur gezeigt. Mit --write eintragen.\n");
}

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
    (new ReflectionMethod($plugin, 'typeNodes'))->invoke($plugin),
    $rendering,
    $data
);

echo count($saetze) . ' Sätze, geändert: ' . $schreiber->refresh($saetze) . "\n";

foreach ($wpdb->get_results($wpdb->prepare(
    "WITH RECURSIVE ast(id) AS (SELECT %d UNION ALL SELECT n.id FROM {$p}nodes n JOIN ast ON n.parent_node_id = ast.id)
     SELECT n.name AS knoten, v.value_text AS text
       FROM {$p}node_records r JOIN ast ON ast.id = r.node_id JOIN {$p}nodes_named n ON n.id = r.node_id
       LEFT JOIN {$p}relation_records v ON v.node_record_id = r.id AND v.relation_id = %d
      WHERE r.record_type = 'user' AND n.id IN (" . implode(',', array_map('intval', array_keys($listen))) . ")
      ORDER BY n.name LIMIT 20",
    ELECTRONIC_PARTS,
    BEZEICHNUNG
)) as $zeile) {
    echo '  ' . $zeile->knoten . ': ' . ($zeile->text ?? '—') . "\n";
}
