<?php declare(strict_types=1);
// Plan 252: (1) Spannungsfestigkeit der fünf neuen Kondensatoren als Feld statt als Satz in der Beschreibung.
// (2) Leere Doppel aufräumen: ein leerer Teilsatz neben einem gefüllten im selben Feld — das Muster entsteht, weil
// ein Pflichtfeld aus Teilen beim Anlegen schon einen leeren Teil mitbringt. Ohne --write wird nur gezeigt.
define('WP_USE_THEMES', false);
require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
use Taxmod\Core\Model\TypedValue;
use Taxmod\WordPress\Plugin;
wp_set_current_user(1);
$schreiben = in_array('--write', $argv, true);
$rc = new ReflectionClass(Plugin::class); $plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$screen = $plugin->screen();
$data = (new ReflectionProperty($screen, 'data'))->getValue($screen);
global $wpdb; $p = $wpdb->prefix . 'taxmod_';

// «Beschreibung» ist an den Vater «Model» gewandert; die Kante wird darum gesucht statt genannt.
const CONDENSATOR = 3642, C_SPANNUNG = 149000104315, EW_WERT = 4234, EW_PREFIX = 4235, EW_EINHEIT = 4236, VOLT = 4050;

// ── 1. Spannungsfestigkeit ────────────────────────────────────────────────────────────────────────
// 16 V ist bei allen fünf eine übliche Spannungsklasse und liegt über allem, was auf den Platinen anliegt (5 V).
$neu = [
    'Kondensator Keramik 10 µF 0805 (mind. 6,3 V)' => 'Kondensator Keramik 10 µF 0805',
    'Kondensator Keramik 1 µF 0805' => 'Kondensator Keramik 1 µF 0805',
    'Kondensator Keramik 100 nF 0805' => 'Kondensator Keramik 100 nF 0805',
    'Elektrolytkondensator 10 µF, Ø 5 mm, RM 2 mm (mind. 10 V)' => 'Elektrolytkondensator 10 µF, Ø 5 mm, RM 2 mm',
    'Kondensator Keramik 1 µF, radial RM 5 mm' => 'Kondensator Keramik 1 µF, radial RM 5 mm',
];
foreach ($neu as $alt => $text) {
    $satz = $wpdb->get_var($wpdb->prepare(
        "SELECT v.node_record_id FROM {$p}relation_records v JOIN {$p}node_records n ON n.id = v.node_record_id
         WHERE n.node_id = %d AND v.value_text IN (%s, %s)", CONDENSATOR, $alt, $text));
    if ($satz === null) { echo "  nicht gefunden: $alt\n"; continue; }
    $satz = (int) $satz;
    $hat = $wpdb->get_var($wpdb->prepare("SELECT value_ref FROM {$p}relation_records WHERE node_record_id = %d AND relation_id = %d AND value_ref IS NOT NULL", $satz, C_SPANNUNG));
    $gefuellt = $hat !== null && $wpdb->get_var("SELECT COUNT(*) FROM {$p}relation_records WHERE node_record_id = " . (int) $hat) > 0;
    $zeile = $wpdb->get_row($wpdb->prepare("SELECT relation_id, value_text FROM {$p}relation_records WHERE node_record_id = %d AND value_text IN (%s, %s)", $satz, $alt, $text));
    $beschreibung = (int) $zeile->relation_id; $textAlt = $zeile->value_text;
    if ($gefuellt && $textAlt === $text) { continue; }
    echo "  $satz: Spannungsfestigkeit 16 V" . ($textAlt !== $text ? ", Beschreibung «{$text}»" : '') . "\n";
    if (! $schreiben) continue;
    $teil = $hat !== null ? (int) $hat : $data->createPart($satz, C_SPANNUNG)->id;
    $data->put($teil, EW_WERT, TypedValue::ofDecimal('16'));
    $data->put($teil, EW_EINHEIT, TypedValue::ofReference(VOLT));
    $data->put($satz, $beschreibung, TypedValue::ofText($text));
}

// ── 2. Leere Doppel ───────────────────────────────────────────────────────────────────────────────
$rows = $wpdb->get_results("SELECT v.node_record_id AS satz, v.relation_id AS feld, v.value_ref AS teil
  FROM {$p}relation_records v
  WHERE v.value_ref_kind = 'record'
    AND NOT EXISTS (SELECT 1 FROM {$p}relation_records x WHERE x.node_record_id = v.value_ref)
    AND EXISTS (SELECT 1 FROM {$p}relation_records w JOIN {$p}relation_records y ON y.node_record_id = w.value_ref
                WHERE w.node_record_id = v.node_record_id AND w.relation_id = v.relation_id AND w.value_ref <> v.value_ref)");
echo count($rows) . " leere Doppel\n";
foreach ($rows as $r) {
    echo "  Satz {$r->satz}, Feld " . $wpdb->get_var("SELECT name FROM {$p}relations_named WHERE id={$r->feld}") . ", Teil {$r->teil}\n";
    if ($schreiben) $data->removeRecord((int) $r->teil);
}
echo $schreiben ? "Fertig.\n" : "Nur gezeigt.\n";
