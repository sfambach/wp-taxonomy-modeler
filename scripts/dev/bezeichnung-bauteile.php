<?php declare(strict_types=1);

/**
 * Bei den Bauteilen ist die Bezeichnung die Zusammenfassung — abgeleitet und gesperrt (2026-09-20).
 *
 *     php scripts/dev/bezeichnung-bauteile.php            # nur zeigen
 *     php scripts/dev/bezeichnung-bauteile.php --write    # umstellen und alle Sätze nachrechnen
 *
 * ⚠️ *Seine Worte ([D-888](../../docs/NewConcept/90-decision-log.md)): «ja, bezeichnung soll das feld sein bei bauteilen»
 * und «bezeichnung sollte eigentlich abgeleitet sein somit readonly für den benutzer».*
 *
 * 1. An jedem Bauteilknoten, der eine Zusammenfassung eingestellt hat, steht dieselbe Liste noch einmal **an der Kante
 *    «Bezeichnung»** — das ist die Zusage «hier wird dieses Feld zusammengesetzt» (dieselbe Adresse wie die Anordnung, D-698).
 * 2. Das Feld «Zusammenfassung» an «Electronic Parts» fällt wieder weg; sein Text steht jetzt in der Bezeichnung.
 * 3. Alle Sätze unter «Electronic Parts» werden einmal nachgerechnet.
 *
 * Wiederholbar: was schon steht, bleibt; nachgerechnet wird immer.
 */

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';
define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

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
/** @var \Taxmod\Core\Service\SettingsEditor $attributes */
$attributes = (new ReflectionProperty($screen, 'attributes'))->getValue($screen);

global $wpdb;
$p = $wpdb->prefix . 'taxmod_';

// Gemessen am 2026-09-20
const ELECTRONIC_PARTS = 3634, BEZEICHNUNG = 149000103839;

$bezeichnung = $editor->relationById(BEZEICHNUNG);

// ── 1. Je Bauteilknoten: dieselbe Liste an die Kante «Bezeichnung» ─────────────────────────────────
$knotenMitWahl = $wpdb->get_results($wpdb->prepare(
    "WITH RECURSIVE ast(id) AS (SELECT %d UNION ALL SELECT n.id FROM {$p}nodes n JOIN ast ON n.parent_node_id = ast.id)
     SELECT s.node_id, GROUP_CONCAT(s.wert_kante_id ORDER BY s.position, s.id) AS felder
       FROM {$p}settings_value s JOIN ast ON ast.id = s.node_id
      WHERE s.attribut = 'summary_fields' AND s.relation_id IS NULL AND s.aktiv = 1
      GROUP BY s.node_id",
    ELECTRONIC_PARTS
));

foreach ($knotenMitWahl as $eintrag) {
    $knoten = $editor->find((int) $eintrag->node_id);
    $felder = array_map('intval', explode(',', (string) $eintrag->felder));

    if ($knoten === null) {
        continue;
    }

    $steht = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}settings_value WHERE attribut = 'summary_fields' AND node_id = %d AND relation_id = %d AND aktiv = 1",
        $knoten->id,
        BEZEICHNUNG
    ));

    echo "«{$knoten->name}»: Bezeichnung aus " . count($felder) . ' Feldern' . ($steht > 0 ? ' (steht schon)' : '') . "\n";

    if ($schreiben && $steht === 0) {
        // ⚠️ *Nicht über `setMembers`: das sieht die Liste **am Knoten** als «steht schon» und schreibt dann nichts an die Kante —
        // gemessen. Hier soll aber genau die Zeile mit Kante entstehen, denn sie ist die Zusage «dieses Feld wird hier zusammengesetzt».*
        $store  = (new ReflectionProperty($attributes, 'settings'))->getValue($attributes);
        $klasse = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT klasse FROM {$p}settings_value WHERE attribut = 'summary_fields' AND node_id = %d AND relation_id IS NULL LIMIT 1",
            $knoten->id
        ));

        foreach ($felder as $stelle => $feldId) {
            $store->addValue(\Taxmod\Core\Model\Setting\SettingsValue::atNode(
                $knoten->id,
                $klasse,
                'summary_fields',
                \Taxmod\Core\Model\TypedValue::ofRelationReference($feldId),
                BEZEICHNUNG,
                $stelle
            ));
        }
    }
}

// ── 2. Das eigene Feld «Zusammenfassung» fällt wieder weg ──────────────────────────────────────────
$extra = $wpdb->get_var($wpdb->prepare(
    "SELECT r.id FROM {$p}relations r JOIN {$p}relations_named n ON n.id = r.id WHERE r.from_node_id = %d AND n.name = %s AND r.hide = 0",
    ELECTRONIC_PARTS,
    'Zusammenfassung'
));

if ($extra !== null) {
    echo "Feld «Zusammenfassung» an Electronic Parts wird entfernt (geparkt)\n";

    if ($schreiben) {
        foreach ($wpdb->get_col($wpdb->prepare("SELECT node_record_id FROM {$p}relation_records WHERE relation_id = %d", (int) $extra)) as $satz) {
            $data->clear((int) $satz, (int) $extra);
        }

        $editor->removeField(ELECTRONIC_PARTS, (int) $extra);
    }
}

if (! $schreiben) {
    exit("— nur gezeigt. Mit --write umstellen.\n");
}

// ── 3. Nachrechnen ─────────────────────────────────────────────────────────────────────────────────
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

foreach (array_slice($saetze, 0, 6) as $satz) {
    echo "  #{$satz}: " . ($wpdb->get_var($wpdb->prepare(
        "SELECT value_text FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d",
        $satz,
        BEZEICHNUNG
    )) ?? '—') . "\n";
}
