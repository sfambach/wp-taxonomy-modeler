<?php declare(strict_types=1);

/**
 * Ein neuer Teil («neu») an einem Feld mit höchstens einem Wert füllt den gehaltenen Teil, statt einen zweiten anzulegen.
 *
 *     php scripts/dev/teil-einzeln-check.php [path/to/wordpress]
 *
 * ⚠️ *Gemessen am 2026-10-23: 41 Bauteile aus dem Reichelt-Wareneingang trugen an Toleranz, Widerstandswert und Leistung je zwei
 * Teile — den leeren, der mit dem Halter entsteht ([D-541](../../docs/NewConcept/90-decision-log.md)), und den mit «neu»
 * geschriebenen —, und ihre Bezeichnung las «#43780 · #43779 · 0207».*
 *
 * *Eine eigene Wiese `__te`: (1) «neu» an einem 1..1-Feld mit leerem Pflichtteil füllt diesen, es bleibt ein Teil; (2) ein zweites
 * «neu», wenn der Teil schon Werte hält, wird abgelehnt; (3) «0» schreibt weiter in den gehaltenen Teil.*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — die Klammer dreht am Ende alles zurück.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;

wp_set_current_user(1);

if (! function_exists('wp_get_ability')) {
    echo "  SKIP this WordPress has no Abilities API\n";
    exit(0);
}

$apply = wp_get_ability('taxmod/apply');
$text  = (new WpdbNodeRepository())->byImplementations([TextType::class])[TextType::class] ?? null;
$model = (int) get_option('taxmod_branch_model_id', 0);
$teile = (int) get_option('taxmod_branch_compositions_id', 0);

if ($apply === null || $text === null || $model === 0 || $teile === 0) {
    echo "  FAIL Gerüst fehlt (apply, Text, Model, Compositions)\n";
    exit(1);
}

$fehler = 0;

$geruest = $apply->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'h', 'name' => '__te Halter', 'parent' => $model],
    ['op' => 'create_node', 'ref' => 'm', 'name' => '__te Teil', 'parent' => $teile],
    ['op' => 'add_field', 'ref' => 'mt', 'node' => '@m', 'target' => $text->id, 'name' => '__te Wert'],
    ['op' => 'add_field', 'ref' => 'komp', 'node' => '@h', 'target' => '@m', 'name' => '__te Komposition', 'kind' => 'composition', 'multiplicity' => '1..1'],
    ['op' => 'create_record', 'ref' => 'r', 'node' => '@h'],
]]);

if (! is_array($geruest)) {
    printf("  FAIL die Wiese entsteht nicht — %s\n", is_wp_error($geruest) ? $geruest->get_error_message() : '?');
    exit(1);
}

$ref = $geruest['refs'];

$teileAm = static function (int $satz, int $feld): array {
    global $wpdb;

    return array_map('intval', $wpdb->get_col($wpdb->prepare(
        "SELECT value_ref FROM {$wpdb->prefix}taxmod_relation_records WHERE node_record_id = %d AND relation_id = %d AND value_ref_kind = 'record'",
        $satz,
        $feld
    )));
};

$vorher = $teileAm((int) $ref['r'], (int) $ref['komp']);
printf("  %s der Halter hält einen Pflichtteil (%d)\n", count($vorher) === 1 ? 'ok  ' : 'FAIL', count($vorher));
$fehler += count($vorher) === 1 ? 0 : 1;

$neu = $apply->execute(['changes' => [
    ['op' => 'set_values', 'record' => $ref['r'], 'values' => [(string) $ref['komp'] => ['neu' => [(string) $ref['mt'] => 'eins']]]],
]]);
$nachher = $teileAm((int) $ref['r'], (int) $ref['komp']);
$gut     = is_array($neu) && $nachher === $vorher;
printf("  %s «neu» füllt den leeren gehaltenen Teil, kein zweiter (%s)\n", $gut ? 'ok  ' : 'FAIL', is_array($neu) ? implode(', ', $nachher) : $neu->get_error_message());
$fehler += $gut ? 0 : 1;

$wert = $wpdb->get_var($wpdb->prepare(
    "SELECT value_text FROM {$wpdb->prefix}taxmod_relation_records WHERE node_record_id = %d AND relation_id = %d",
    $vorher[0] ?? 0,
    (int) $ref['mt']
));
printf("  %s der Wert steht im gehaltenen Teil (%s)\n", $wert === 'eins' ? 'ok  ' : 'FAIL', (string) $wert);
$fehler += $wert === 'eins' ? 0 : 1;

$zweit = $apply->execute(['changes' => [
    ['op' => 'set_values', 'record' => $ref['r'], 'values' => [(string) $ref['komp'] => ['neu' => [(string) $ref['mt'] => 'zwei']]]],
]]);
$gut = is_wp_error($zweit) && $teileAm((int) $ref['r'], (int) $ref['komp']) === $vorher;
printf("  %s ein zweites «neu» an einem gefüllten Teil wird abgelehnt\n", $gut ? 'ok  ' : 'FAIL');
$fehler += $gut ? 0 : 1;

$null = $apply->execute(['changes' => [
    ['op' => 'set_values', 'record' => $ref['r'], 'values' => [(string) $ref['komp'] => ['0' => [(string) $ref['mt'] => 'drei']]]],
]]);
$gut = is_array($null) && $teileAm((int) $ref['r'], (int) $ref['komp']) === $vorher;
printf("  %s «0» schreibt weiter in den gehaltenen Teil\n", $gut ? 'ok  ' : 'FAIL');
$fehler += $gut ? 0 : 1;

exit($fehler === 0 ? 0 : 1);
