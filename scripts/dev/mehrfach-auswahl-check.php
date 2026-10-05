<?php declare(strict_types=1);

/**
 * Ein mehrfaches Auswahlfeld hat beim Bearbeiten nur seine Zeilen — kein zweites, einfaches Auswahlfeld davor.
 *
 *     php scripts/dev/mehrfach-auswahl-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-881](../../docs/NewConcept/90-decision-log.md), sein Befund:** *«bei voltage blaster herkunft sieht komisch aus gleich zwei
 * leere felder übereinander».* *Gemessen am 2026-09-19: über den Zeilen `…[<Kante>][values][]` stand ein Kasten `…[<Kante>]` ohne «+»,
 * gezeichnet vom Weg für eine einfache Auswahl mit den Feldern des Gewählten daneben.*
 *
 * *Geprüft wird jede Seite eines Satzes, den der Lauf findet: ein Satz, der ein mehrfaches Feld auf eine Auswahl hat («Herkunft» am
 * Vater «Model» erbt jeder), geöffnet auf seinem Knoten. Kein Name eines Knotens steht hier — gesucht wird die Gestalt.*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende alles zurück.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Plugin;

global $wpdb;
wp_set_current_user(1);

$p = $wpdb->prefix . 'taxmod_';

// *Ein Satz mit einem mehrfachen Feld, das auf einen Knoten ohne eigene Sätze zeigt (eine Auswahl) — der erste, den die Tabellen hergeben.*
$fund = $wpdb->get_row(
    "SELECT r.id AS satz, r.node_id AS knoten, k.id AS kante
       FROM {$p}relations k
       JOIN {$p}nodes z ON z.id = k.to_node_id AND z.klasse LIKE '%Choice'
       JOIN {$p}node_records r ON r.record_type = 'user'
       -- *Ein Satz, der schon ein Feld von «Model» füllt, liegt sicher darunter und erbt die Kante.*
       JOIN {$p}relation_records v ON v.node_record_id = r.id AND v.relation_id IN (SELECT id FROM {$p}relations WHERE from_node_id = 402)
      WHERE k.multiplicity IN ('0..*', '1..*') AND k.kind = 'aggregation' AND k.hide = 0 AND k.from_node_id = 402
      ORDER BY r.id LIMIT 1"
);

if ($fund === null) {
    echo "  FAIL kein Satz mit einem mehrfachen Auswahlfeld gefunden — der Wächter prüft nichts\n";
    exit(1);
}

$_GET     = ['page' => 'taxmod', 'taxmod_node' => (string) $fund->knoten, 'taxmod_open_record' => (string) $fund->satz];
$_REQUEST = $_GET;
$rc       = new ReflectionClass(Plugin::class);
$plugin   = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$markup   = $plugin->screen()->render();

$zeilen = substr_count($markup, 'name="taxmod_value[' . $fund->satz . '][' . $fund->kante . '][values][]"');
$kasten = substr_count($markup, 'name="taxmod_value[' . $fund->satz . '][' . $fund->kante . ']"');

$gut = $zeilen > 0 && $kasten === 0;
printf("  %s Satz #%d, Feld %d: %d Zeilen, %d einfacher Kasten\n", $gut ? 'ok  ' : 'FAIL', $fund->satz, $fund->kante, $zeilen, $kasten);

// ⚠️ **Und in der Satztabelle stehen alle Werte** ([D-900](../../docs/NewConcept/90-decision-log.md)) — *gemessen am 2026-09-21: drei
// Herkunftsarten gespeichert, in der Zelle stand nur die letzte. Gesucht wird ein Satz mit mindestens zwei Werten in einem solchen Feld.*
$mehrere = $wpdb->get_row(
    "SELECT r.id AS satz, r.node_id AS knoten, v.relation_id AS kante, GROUP_CONCAT(n.name ORDER BY v.position SEPARATOR '\t') AS namen
       FROM {$p}relation_records v
       JOIN {$p}relations k ON k.id = v.relation_id AND k.multiplicity IN ('0..*', '1..*') AND k.hide = 0
       JOIN {$p}nodes z ON z.id = k.to_node_id AND z.klasse LIKE '%Choice'
       JOIN {$p}nodes_named n ON n.id = v.value_ref
       JOIN {$p}node_records r ON r.id = v.node_record_id AND r.record_type = 'user'
      GROUP BY r.id, r.node_id, v.relation_id HAVING COUNT(*) > 1 ORDER BY r.id DESC LIMIT 1"
);

if ($mehrere === null) {
    echo "  FAIL kein Satz mit zwei Werten in einem mehrfachen Auswahlfeld — der Wächter prüft nichts\n";
    exit(1);
}

$_GET     = ['page' => 'taxmod', 'taxmod_node' => (string) $mehrere->knoten];
$_REQUEST = $_GET;
$tabelle  = $plugin->screen()->render();
$liste    = '<span class="taxmod-value taxmod-ref-list">' . implode('<br>', array_map('esc_html', explode("\t", (string) $mehrere->namen))) . '</span>';
$alle     = str_contains($tabelle, $liste);
printf("  %s Satz #%d, Feld %d: in der Tabelle stehen alle Werte (%s)\n", $alle ? 'ok  ' : 'FAIL', $mehrere->satz, $mehrere->kante, str_replace("\t", ', ', (string) $mehrere->namen));

exit($gut && $alle ? 0 : 1);
