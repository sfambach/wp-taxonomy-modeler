<?php declare(strict_types=1);

/**
 * Ob ein Wert ein eigener Teil wird, sagt die Kante — nicht der Ast des Ziels.
 *
 *     php scripts/dev/kante-entscheidet-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-938](../../docs/NewConcept/90-decision-log.md), sein Wort:** *«ja bitte die kante entscheidet. dann können wir danach den
 * baum neu organisieren»*. *Bis dahin bekam eine Komposition nur einen Teil, wenn ihr Ziel unter `Compositions` lag; eine
 * Aggregation dorthin bekam trotzdem einen.*
 *
 * *Eine eigene Wiese `__ke`: (1) eine Komposition auf einen Knoten unter `Model` hält einen Teil; (2) eine Aggregation auf einen
 * Knoten unter `Compositions` hält keinen. Gemessen am Pflichtteil, der mit dem Halter entsteht.*
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
    ['op' => 'create_node', 'ref' => 'h', 'name' => '__ke Halter', 'parent' => $model],
    ['op' => 'create_node', 'ref' => 'm', 'name' => '__ke Im Modell', 'parent' => $model],
    ['op' => 'add_field', 'ref' => 'mt', 'node' => '@m', 'target' => $text->id, 'name' => '__ke Wert'],
    ['op' => 'create_node', 'ref' => 'c', 'name' => '__ke In Compositions', 'parent' => $teile],
    ['op' => 'add_field', 'ref' => 'ct', 'node' => '@c', 'target' => $text->id, 'name' => '__ke Wert'],
    ['op' => 'add_field', 'ref' => 'komp', 'node' => '@h', 'target' => '@m', 'name' => '__ke Komposition', 'kind' => 'composition'],
    ['op' => 'add_field', 'ref' => 'aggr', 'node' => '@h', 'target' => '@c', 'name' => '__ke Aggregation', 'kind' => 'aggregation'],
    ['op' => 'create_record', 'ref' => 'r', 'node' => '@h'],
]]);

if (! is_array($geruest)) {
    printf("  FAIL die Wiese entsteht nicht — %s\n", is_wp_error($geruest) ? $geruest->get_error_message() : '?');
    exit(1);
}

$ref = $geruest['refs'];

// ⚠️ *Besitz zeigt sich beim Anlegen: ein Pflichtfeld, das einen eigenen Teil hält, bekommt ihn mit dem Halter
// ([D-541](../../docs/NewConcept/90-decision-log.md)); eines, das nur verweist, bekommt nichts.*
$zaehle = static function (int $knoten): int {
    global $wpdb;

    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}taxmod_node_records WHERE node_id = %d",
        $knoten
    ));
};

$anzahl = $zaehle((int) $ref['m']);
printf("  %s eine Komposition auf einen Knoten unter Model hält einen eigenen Teil (%d)\n", $anzahl === 1 ? 'ok  ' : 'FAIL', $anzahl);
$fehler += $anzahl === 1 ? 0 : 1;

$anzahl = $zaehle((int) $ref['c']);
printf("  %s eine Aggregation auf einen Knoten unter Compositions hält keinen (%d)\n", $anzahl === 0 ? 'ok  ' : 'FAIL', $anzahl);
$fehler += $anzahl === 0 ? 0 : 1;

$komposition = $apply->execute(['changes' => [
    ['op' => 'set_values', 'record' => $ref['r'], 'values' => [(string) $ref['komp'] => ['0' => [(string) $ref['mt'] => 'eins']]]],
]]);
$gut = is_array($komposition);
printf("  %s der Teil nimmt seinen Wert auf%s\n", $gut ? 'ok  ' : 'FAIL', $gut ? '' : ' — ' . $komposition->get_error_message());
$fehler += $gut ? 0 : 1;

exit($fehler === 0 ? 0 : 1);
