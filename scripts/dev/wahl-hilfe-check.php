<?php declare(strict_types=1);

/**
 * Eine Auswahl zeigt neben jeder Konstanten deren Hilfe — man sieht beim Wählen, was «0207» ist.
 *
 *     php scripts/dev/wahl-hilfe-check.php [path/to/wordpress]
 *
 * ⚠️ *Sein Wort am 2026-10-29: «die tht unterschiede kenne ich nicht … kannst das mal anpassen so das man das beim auswählen sieht?»*
 *
 * *Gesucht wird die Gestalt: ein Satz, dessen Feld auf eine Wahl zeigt, unter der (bis zwei Ebenen tief) eine Konstante mit Hilfe hängt —
 * geöffnet muss die Hilfe in der Auswahl stehen.*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Plugin;

global $wpdb;
wp_set_current_user(1);

$p = $wpdb->prefix . 'taxmod_';

$sucheFund = static fn (): ?object => $wpdb->get_row(
    "SELECT r.id AS satz, t.text_help AS hilfe
       FROM {$p}node_records r
       JOIN {$p}relations k ON k.from_node_id = r.node_id AND k.kind = 'aggregation' AND k.hide = 0
       JOIN {$p}nodes c ON c.hide = 0 AND (c.parent_node_id = k.to_node_id
            OR c.parent_node_id IN (SELECT m.id FROM {$p}nodes m WHERE m.parent_node_id = k.to_node_id AND m.hide = 0))
       JOIN {$p}label_texts t ON t.label_id = c.label_id AND t.text_help <> ''
      WHERE r.record_type = 'user' AND c.klasse LIKE '%Constant' ORDER BY r.id DESC LIMIT 1"
);

$fund = $sucheFund();

// ⚠️ *Grenzlauf 2026-11-17 ([D-1427](../../docs/NewConcept/90-decision-log.md)): ohne diese Gestalt im Bestand legt der Wächter sie selbst an —
// eine Auswahl, deren Konstante eine Hilfe hat, ein Feld darauf und einen Satz daran.*
if ($fund === null) {
    require_once __DIR__ . '/lib/beispiel.php';

    taxmodBeispiel([
        ['op' => 'create_node', 'ref' => 'wahl', 'name' => '__wh Wahl', 'parent' => (int) get_option('taxmod_branch_constants_id'), 'class' => 'Choice'],
        // *Eine Ebene wäre ein Auswahlfeld ohne Hilfe; erst mit einer Gruppe dazwischen zeichnet der Wähler den Baum.*
        ['op' => 'create_node', 'ref' => 'gruppe', 'name' => '__wh Gruppe', 'parent' => '@wahl', 'class' => 'Choice'],
        ['op' => 'create_node', 'ref' => 'k', 'name' => '__wh Erste', 'parent' => '@gruppe', 'class' => 'Constant'],
        ['op' => 'set_label', 'node' => '@k', 'role' => 'help', 'text' => '__wh Hilfe der Konstanten'],
        ['op' => 'create_node', 'ref' => 'ding', 'name' => '__wh Ding', 'parent' => 402],
        ['op' => 'add_field', 'node' => '@ding', 'target' => '@wahl', 'name' => '__wh Feld', 'kind' => 'aggregation'],
        ['op' => 'create_record', 'node' => '@ding'],
    ]);

    $fund = $sucheFund();
}

if ($fund === null) {
    echo "  FAIL kein Satz mit einer Wahl, deren Konstante eine Hilfe hat — der Wächter prüft nichts\n";
    exit(1);
}

$_GET     = ['page' => 'taxmod', 'taxmod_open_record' => (string) $fund->satz];
$_REQUEST = $_GET;
$rc       = new ReflectionClass(Plugin::class);
$plugin   = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen   = $plugin->screen();
// ⚠️ *Der gemeinsame Baum kommt seit [D-1418](../../docs/NewConcept/90-decision-log.md) als nachgeladenes Stück `pick`; die Seite und das
// Stück zusammen sind, was der Browser zeigt (Grenzlauf 2026-11-17, D-1427, `PR-9`).*
$markup   = $screen->render() . (new ReflectionMethod($screen, 'sharedPickerTree'))->invoke($screen);

$gut = str_contains($markup, '<span class="taxmod-tree-help">' . esc_html((string) $fund->hilfe) . '</span>');
printf("  %s Satz #%d zeigt in der Auswahl die Hilfe einer Konstanten («%s»)\n", $gut ? 'ok  ' : 'FAIL', $fund->satz, mb_substr((string) $fund->hilfe, 0, 50));

exit($gut ? 0 : 1);
