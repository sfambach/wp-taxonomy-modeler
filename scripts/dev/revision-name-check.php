<?php declare(strict_types=1);

/**
 * Eine Revision heisst mit ihrem Halter — «386-SC-HG · A» statt «A».
 *
 *     php scripts/dev/revision-name-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-903](../../docs/NewConcept/90-decision-log.md), sein Wort** auf den Vorschlag «FIC 386-SC-HG · A»:
 * *«ok»*. *Gemessen am 2026-09-22: das Board im Escom-PC hiess in «Verwiesen von» nur «A».*
 *
 * *Gesucht wird die Gestalt: je eindeutiger Aggregation, deren Ziel selbst nichts eindeutig hält (letzte Stufe), ein gehaltener Satz. Sein
 * Wort muss mit dem Wort seines Halters beginnen. Und die Gegenprobe: ein Satz einer mittleren Stufe (hält selbst eindeutig) bekommt keinen
 * Halter vorangestellt.*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende alles zurück.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Renderer\SummaryRenderer;
use Taxmod\WordPress\Plugin;

global $wpdb;
wp_set_current_user(1);

$p = $wpdb->prefix . 'taxmod_';

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
/** @var \Taxmod\Core\Service\Rendering $rendering */
$rendering = (new ReflectionProperty($screen, 'rendering'))->getValue($screen);
/** @var \Taxmod\Core\Service\ModelEditor $editor */
$editor = (new ReflectionProperty($screen, 'editor'))->getValue($screen);

$haeltEindeutig = "EXISTS (SELECT 1 FROM {$p}relations u WHERE u.from_node_id = k.to_node_id AND u.is_unique = 1 AND u.kind = 'aggregation' AND u.hide = 0)";

$fund = static fn (string $stufe): ?object => $wpdb->get_row(
    "SELECT k.id AS kante, v.value_ref AS satz, v.node_record_id AS halter
       FROM {$p}relations k
       JOIN {$p}relation_records v ON v.relation_id = k.id AND v.value_ref_kind = 'record'
      WHERE k.is_unique = 1 AND k.kind = 'aggregation' AND k.hide = 0 AND {$stufe}
      ORDER BY v.id DESC LIMIT 1"
);

$wortUeber = static function (int $kante, int $satz) use ($rendering, $editor): string {
    $relation = $editor->relationById($kante);

    return $relation === null ? '' : (string) ($rendering->summaryTextsOf([['relation' => $relation, 'records' => [$satz], 'ownWords' => true]])[$kante][$satz] ?? '');
};

$fehler = 0;
$letzte = $fund("NOT {$haeltEindeutig}");
$mitte  = $fund($haeltEindeutig);

if ($letzte === null) {
    echo "  FAIL keine Revision gefunden — der Wächter prüft nichts\n";
    exit(1);
}

// *Das Wort des Halters über irgendeine Kante, die auf ihn zeigt — hier die, die ihn eindeutig hält, falls es sie gibt; sonst allein.*
$halterKante = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT relation_id FROM {$p}relation_records WHERE value_ref = %d AND value_ref_kind = 'record' LIMIT 1",
    $letzte->halter
));
$halterWort = $halterKante === 0 ? '' : $wortUeber($halterKante, (int) $letzte->halter);
$satzWort   = $wortUeber((int) $letzte->kante, (int) $letzte->satz);
$gut        = $halterWort !== '' && str_starts_with($satzWort, $halterWort . SummaryRenderer::SEPARATOR);

printf("  %s Revision #%d heisst «%s» — beginnt mit ihrem Halter «%s»\n", $gut ? 'ok  ' : 'FAIL', $letzte->satz, $satzWort, $halterWort);
$fehler += $gut ? 0 : 1;

if ($mitte !== null) {
    $mitteWort    = $wortUeber((int) $mitte->kante, (int) $mitte->satz);
    $halterVorn   = $wortUeber((int) $mitte->kante, (int) $mitte->halter);
    $ohneHalter   = $halterVorn === '' || ! str_starts_with($mitteWort, $halterVorn . SummaryRenderer::SEPARATOR);

    printf("  %s Satz #%d einer mittleren Stufe heisst «%s» — ohne Halter davor\n", $ohneHalter ? 'ok  ' : 'FAIL', $mitte->satz, $mitteWort);
    $fehler += $ohneHalter ? 0 : 1;
}

exit($fehler === 0 ? 0 : 1);
