<?php declare(strict_types=1);

/**
 * Ein Feld zeigt im Formular die Hilfe des Knotens, auf den es zeigt — und die Modellknoten haben eine.
 *
 *     php scripts/dev/hilfetext-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-905](../../docs/NewConcept/90-decision-log.md), sein Wort:** *«ergänze mal die help texte in Esmplar steht nix zumindest bei den
 * modellen und datntypen sollte etwas stehen».* *Gemessen am 2026-09-22: 4 Hilfetexte im ganzen Modell, keiner an «Models» oder «Exemplare».*
 *
 * *Gesucht wird die Gestalt: (1) ein Satz, dessen Knoten ein Feld auf einen Knoten mit Hilfe trägt — geöffnet muss die Hilfe dastehen;
 * (2) unter «Model» hat jeder sichtbare Knoten, der Sätze trägt, eine Hilfe. Konstanten und leere Gliederungsknoten zählen nicht.*
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

$p      = $wpdb->prefix . 'taxmod_';
$fehler = 0;

$mitHilfe = "EXISTS (SELECT 1 FROM {$p}label_texts t WHERE t.label_id = z.label_id AND t.text_help <> '')";

$fund = $wpdb->get_row(
    "SELECT r.id AS satz, t.text_help AS hilfe
       FROM {$p}node_records r
       JOIN {$p}relations k ON k.from_node_id = r.node_id AND k.kind = 'aggregation' AND k.hide = 0
       JOIN {$p}nodes z ON z.id = k.to_node_id AND {$mitHilfe}
       JOIN {$p}label_texts t ON t.label_id = z.label_id AND t.text_help <> ''
      WHERE r.record_type = 'user' ORDER BY r.id DESC LIMIT 1"
);

if ($fund === null) {
    echo "  FAIL kein Satz mit einem Feld auf einen Knoten mit Hilfe — der Wächter prüft nichts\n";
    exit(1);
}

$_GET     = ['page' => 'taxmod', 'taxmod_open_record' => (string) $fund->satz];
$_REQUEST = $_GET;
$rc       = new ReflectionClass(Plugin::class);
$plugin   = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$markup   = $plugin->screen()->render();

$gut = str_contains($markup, esc_attr((string) $fund->hilfe)) || str_contains($markup, esc_html((string) $fund->hilfe));
printf("  %s Satz #%d zeigt die Hilfe seines Feldes («%s»)\n", $gut ? 'ok  ' : 'FAIL', $fund->satz, mb_substr((string) $fund->hilfe, 0, 50));
$fehler += $gut ? 0 : 1;

$ohne = $wpdb->get_col(
    "WITH RECURSIVE ast(id) AS (SELECT 402 UNION ALL SELECT n.id FROM {$p}nodes n JOIN ast ON n.parent_node_id = ast.id AND n.hide = 0)
     SELECT nn.name FROM {$p}nodes z JOIN ast ON ast.id = z.id JOIN {$p}nodes_named nn ON nn.id = z.id
      WHERE EXISTS (SELECT 1 FROM {$p}node_records r WHERE r.node_id = z.id AND r.record_type = 'user') AND NOT {$mitHilfe}"
);

printf("  %s jeder Modellknoten mit Sätzen hat eine Hilfe%s\n", $ohne === [] ? 'ok  ' : 'FAIL', $ohne === [] ? '' : ' — es fehlen: ' . implode(', ', $ohne));
$fehler += $ohne === [] ? 0 : 1;

exit($fehler === 0 ? 0 : 1);
