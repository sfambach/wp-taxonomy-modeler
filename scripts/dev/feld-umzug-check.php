<?php declare(strict_types=1);

/**
 * A field moves or merges through `taxmod/apply` without hiding a value.
 *
 *     php scripts/dev/feld-umzug-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-941](../../docs/NewConcept/90-decision-log.md), his word:** *«Ja, bauen»* — for the tidy-up of D-940. *A sandbox `__fu`:
 * (1) move_field is refused while a record with values would end up outside; (2) after the node moved, it goes through and the value
 * stays; (3) merge_field puts the value onto the other field and parks the old one.*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **No check writes into the owner's model** — the bracket rolls everything back at the end.
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

if ($apply === null || $text === null || $model === 0) {
    echo "  FAIL scaffold missing (apply, Text, Model)\n";
    exit(1);
}

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

$geruest = $apply->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'alt', 'name' => '__fu Alt', 'parent' => $model],
    ['op' => 'create_node', 'ref' => 'neu', 'name' => '__fu Neu', 'parent' => $model],
    ['op' => 'create_node', 'ref' => 'chip', 'name' => '__fu Chip', 'parent' => '@alt'],
    ['op' => 'add_field', 'ref' => 'f', 'node' => '@alt', 'target' => $text->id, 'name' => '__fu Teilenummer', 'multiplicity' => '0..1'],
    ['op' => 'add_field', 'ref' => 'g', 'node' => '@neu', 'target' => $text->id, 'name' => '__fu Nummer', 'multiplicity' => '0..1'],
    ['op' => 'create_record', 'ref' => 'r', 'node' => '@chip'],
    ['op' => 'set_values', 'record' => '@r', 'values' => ['@f' => 'Z80']],
]]);

if (! is_array($geruest)) {
    printf("  FAIL the sandbox is not built — %s\n", is_wp_error($geruest) ? $geruest->get_error_message() : '?');
    exit(1);
}

$ref  = $geruest['refs'];
$wert = static function (int $kante) use ($ref): ?string {
    global $wpdb;

    $text = $wpdb->get_var($wpdb->prepare(
        "SELECT value_text FROM {$wpdb->prefix}taxmod_relation_records WHERE node_record_id = %d AND relation_id = %d",
        $ref['r'],
        $kante
    ));

    return $text === null ? null : (string) $text;
};

$zu = $apply->execute(['changes' => [['op' => 'move_field', 'node' => $ref['alt'], 'field' => $ref['f'], 'to' => $ref['neu']]]]);
$pruefe(is_wp_error($zu), 'move_field is refused while the record still lies under the old node');

$um = $apply->execute(['changes' => [
    ['op' => 'move_node', 'node' => $ref['chip'], 'parent' => $ref['neu']],
    ['op' => 'move_field', 'node' => $ref['alt'], 'field' => $ref['f'], 'to' => $ref['neu']],
]]);
$pruefe(is_array($um) && $wert((int) $ref['f']) === 'Z80', 'after the node moved, the field follows and the value stays');

$zusammen = $apply->execute(['changes' => [['op' => 'merge_field', 'node' => $ref['neu'], 'field' => $ref['f'], 'into' => $ref['g']]]]);
$pruefe(is_array($zusammen) && $wert((int) $ref['g']) === 'Z80' && $wert((int) $ref['f']) === null, 'merge_field moves the value onto the other field');

global $wpdb;

$verweis = $apply->execute(['changes' => [
    ['op' => 'add_field', 'ref' => 'n', 'node' => $ref['neu'], 'target' => $ref['alt'], 'name' => '__fu Nachfolger', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
    ['op' => 'retarget_field', 'node' => $ref['neu'], 'field' => '@n', 'target' => $ref['neu']],
]]);
$art = is_array($verweis) ? $wpdb->get_var($wpdb->prepare("SELECT kind FROM {$wpdb->prefix}taxmod_relations WHERE id = %d", $verweis['refs']['n'])) : null;
$pruefe($art === 'aggregation', 'retarget_field keeps an aggregation an aggregation');

exit($fehler === 0 ? 0 : 1);
