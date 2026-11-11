<?php declare(strict_types=1);
/**
 * A record created with its name only is marked «Review needed» until it is saved once in the full form (D-1255).
 *
 *     WP_ROOT=/path/to/wordpress php scripts/dev/record-review-check.php
 *
 * @see docs/gestaltung-und-architektur.md V6
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);
define('DOING_AJAX', true);

$root = $argv[1] ?? getenv('WP_ROOT') ?: '';

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Pass the WordPress folder as the first argument or WP_ROOT.\n");
    exit(2);
}

require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

// The table must stand before the bracket opens: a CREATE TABLE inside it would commit the bracket.
\Taxmod\WordPress\Persistence\Schema::ensureCurrent();

require __DIR__ . '/lib/no-write.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\ModelDoor;
use Taxmod\WordPress\Persistence\RecordReviews;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;

final class ReviewStop extends RuntimeException
{
}

$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $bad;
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . ($passed || $detail === '' ? '' : " — $detail") . "\n";
    $bad += $passed ? 0 : 1;
}

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 1));

$plugin = (new ReflectionClass(\Taxmod\WordPress\Plugin::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($plugin, 'file'))->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$model = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin)->rootOf(Branch::Model);

$text  = (new WpdbNodeRepository())->byImplementations([TextType::class])[TextType::class] ?? null;
$built = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'h', 'name' => '__rv Hersteller', 'parent' => $model->id],
    ['op' => 'add_field', 'ref' => 'name', 'node' => '@h', 'target' => $text?->id ?? 0, 'name' => '__rv Name', 'kind' => 'composition', 'multiplicity' => '1..1'],
    ['op' => 'create_record', 'ref' => 'alt', 'node' => '@h'],
    ['op' => 'set_values', 'record' => '@alt', 'values' => ['@name' => 'Made in full']],
    ['op' => 'add_field', 'ref' => 'maker', 'node' => '@h', 'target' => '@h', 'name' => '__rv Maker', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
    ['op' => 'create_record', 'ref' => 'kid', 'node' => '@h'],
    ['op' => 'set_values', 'record' => '@kid', 'values' => ['@name' => 'Made by alt', '@maker' => '@alt']],
]]);

check('the test node is built', is_array($built), is_wp_error($built) ? $built->get_error_message() : '');

if (! is_array($built)) {
    exit(1);
}

$ref = $built['refs'];

add_filter('wp_die_ajax_handler', static fn (): callable => static function (): never {
    throw new ReviewStop('json');
});
add_filter('wp_redirect', static function (): never {
    throw new ReviewStop('redirect');
});

$send = static function (string $hook, array $post): string {
    $_POST    = $post;
    $_REQUEST = $post;
    ob_start();

    try {
        do_action($hook);
    } catch (ReviewStop) {
        // The act is through; what it said is in the buffer.
    }

    return (string) ob_get_clean();
};

echo "\n== quick create marks ==\n";

$answer = json_decode($send('admin_post_' . NodesScreen::RECORD_CREATE_ACTION, [
    'action'      => NodesScreen::RECORD_CREATE_ACTION,
    '_ajax_nonce' => wp_create_nonce(NodesScreen::RECORD_CREATE_ACTION),
    'node'        => (string) $ref['h'],
    'name'        => 'Quick Maker',
]), true);
$quick = (int) ($answer['data']['id'] ?? 0);

check('«New here …» creates the record', $quick > 0, wp_json_encode($answer));
check('the quick record needs a review', RecordReviews::among([$quick]) === [$quick]);
check('a record made in the full form does not', RecordReviews::among([$ref['alt']]) === []);
check('the module door names it', (new ModelDoor())->needingReview([$quick, $ref['alt']]) === [$quick]);

$read  = wp_get_ability('taxmod/read-node')->execute(['node' => $ref['h']]);
$flags = [];

foreach (is_array($read) ? ($read['records'] ?? []) : [] as $record) {
    $flags[(int) ($record['id'] ?? 0)] = $record['needs_review'] ?? false;
}

check('read-node flags the quick record', ($flags[$quick] ?? false) === true, wp_json_encode($flags));
check('read-node leaves the other unflagged', array_key_exists($ref['alt'], $flags) && $flags[$ref['alt']] === false, wp_json_encode($flags));
check('the form shows «Review needed»', str_contains(NodesScreen::reviewNote($quick), 'taxmod-review-needed'));
check('no note on a reviewed record', NodesScreen::reviewNote($ref['alt']) === '');

// D-1271: a module's form carries the address «Create» in a record dialog posts to; without it the button did nothing.
$editor = (string) apply_filters(\Taxmod\WordPress\Plugin::RECORD_EDITOR_FILTER, '', $quick, admin_url());
check('a module\'s record form tells the dialog where to create', str_contains($editor, 'data-record-create='), substr($editor, -200));

// D-1278: a node that chose a table for showing its records still gets a form when one of them is edited in a module.
$tabled = wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'set_setting', 'node' => $ref['h'], 'attribute' => 'renderer', 'value' => 'table']]]);
check('the test node chooses a table', is_array($tabled), is_wp_error($tabled) ? $tabled->get_error_message() : '');
$tableEditor = (string) apply_filters(\Taxmod\WordPress\Plugin::RECORD_EDITOR_FILTER, '', $quick, admin_url());
check('its record is still edited as a form, field under field', str_contains($tableEditor, 'taxmod-form-row') && ! str_contains($tableEditor, '<table class="taxmod-table'), substr($tableEditor, 0, 300));
wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'set_setting', 'node' => $ref['h'], 'attribute' => 'renderer', 'value' => '']]]);

// D-1274: a chooser among a module's field controls brings its dialog body with it, not only a stub for a template that page lacks.
$controls = (array) apply_filters(\Taxmod\WordPress\Plugin::FIELD_CONTROLS_FILTER, [], $ref['h'], [$ref['maker']], 'rv', 'rv-form');
$chooser  = (string) ($controls[$ref['maker']] ?? '');
check('a module\'s record chooser lists the records in its dialog', str_contains($chooser, 'Made in full') && ! str_contains($chooser, 'taxmod-record-stub'), substr($chooser, 0, 300));

$door = new ModelDoor();
$door->markForReview([$ref['alt']]);
check('a module marks a record it made with what it could derive', RecordReviews::among([$ref['alt']]) === [$ref['alt']]);
RecordReviews::clear($ref['alt']);

// D-1276: values sent with create_record are written, not dropped while the call says «applied».
$made  = wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'create_record', 'ref' => 'withvals', 'node' => $ref['h'], 'values' => [(string) $ref['name'] => 'Made at once', (string) $ref['maker'] => (string) $ref['alt']]]]]);
$given = is_array($made) ? (new ModelDoor())->valuesOf([(int) $made['refs']['withvals']]) : [];
check('create_record writes the values sent with it', str_contains(wp_json_encode($given), 'Made at once'), is_wp_error($made) ? $made->get_error_message() : wp_json_encode($given));
if (is_array($made)) {
    wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'remove_record', 'record' => (int) $made['refs']['withvals']]]]);
}

$pointing = $door->referrersOf([$ref['alt'], $ref['kid']]);
check('the door names who points at a record, through any field', $pointing === [$ref['alt'] => [$ref['kid']]], wp_json_encode($pointing));

// D-1270: removing a record empties only a row that owns it; a row that merely points at it stays.
$removed = wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'remove_record', 'record' => $ref['alt']]]]);
check('a record another points at by aggregation can be removed', is_array($removed), is_wp_error($removed) ? $removed->get_error_message() : '');
check('the pointing row is left as it was', ($door->referrersOf([$ref['alt']])[$ref['alt']] ?? []) === [$ref['kid']], wp_json_encode($door->referrersOf([$ref['alt']])));

// D-1280: an empty unit-value part has no word in the record block (Lager «Technische Daten»), not its number.
global $wpdb;
$unitValueNode = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . $wpdb->prefix . 'taxmod_nodes WHERE klasse = %s ORDER BY id LIMIT 1', \Taxmod\Core\Model\NodeClass\UnitValue::class));
$withPart      = $unitValueNode === 0 ? null : wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'add_field', 'ref' => 'amount', 'node' => $ref['h'], 'target' => $unitValueNode, 'name' => '__rv Amount', 'kind' => 'composition', 'multiplicity' => '0..1'],
    ['op' => 'create_record', 'ref' => 'emptyPart', 'node' => $unitValueNode],
]]);

if (is_array($withPart)) {
    $wpdb->insert($wpdb->prefix . 'taxmod_relation_records', ['node_record_id' => $ref['kid'], 'relation_id' => $withPart['refs']['amount'], 'locale' => '', 'position' => 0, 'value_ref' => $withPart['refs']['emptyPart'], 'value_ref_kind' => 'record']);
    $block = render_block(['blockName' => 'taxmod/record', 'attrs' => ['record' => $ref['kid'], 'rowFields' => (string) $withPart['refs']['amount']], 'innerBlocks' => []]);
    check('an empty unit-value part shows no number', ! str_contains($block, '#' . $withPart['refs']['emptyPart']), $block);
} else {
    check('an empty unit-value part shows no number', false, 'no unit-value node to test with');
}

echo "\n== saving the full form clears ==\n";

$action = (string) (new ReflectionClass(NodesScreen::class))->getConstant('ACTION');
$values = (string) (new ReflectionClass(NodesScreen::class))->getConstant('VALUE_FIELD');
$send('admin_post_' . $action, [
    'action'         => $action,
    'do'             => 'save_record',
    'id'             => (string) $ref['h'],
    'node_record_id' => (string) $quick,
    '_taxmod_nonce'  => wp_create_nonce($action . '_' . $ref['h']),
    $values          => [$ref['name'] => 'Quick Maker GmbH'],
]);

$saved = '';

foreach (wp_get_ability('taxmod/read-node')->execute(['node' => $ref['h']])['records'] ?? [] as $record) {
    foreach ((int) ($record['id'] ?? 0) === $quick ? ($record['values'] ?? []) : [] as $value) {
        $saved = (string) ($value['value'] ?? '');
    }
}

check('the full form saved', $saved === 'Quick Maker GmbH', $saved);
check('after one save it no longer needs a review', RecordReviews::among([$quick]) === []);

// D-1281: a record whose chosen summary fields are empty is called by its name, not «#<id>».
wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'set_list', 'node' => $ref['h'], 'attribute' => 'summary_fields', 'members' => [$ref['maker']]]]]);
$found = wp_get_ability('taxmod/query')->execute(['node' => $ref['h'], 'where' => [['field' => $ref['name'], 'op' => 'eq', 'value' => 'Quick Maker GmbH']]]);
$word  = (string) (is_array($found) ? ($found['records'][0]['word'] ?? '') : '');
check('empty summary fields fall back to the name', $word === 'Quick Maker GmbH', wp_json_encode($found));

// D-1281: also when the node asked about has no choice of its own and the choice sits at the record's node below it (query on a parent branch).
$below = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'branch', 'name' => '__rv Branch', 'parent' => $model->id],
    ['op' => 'add_field', 'ref' => 'bname', 'node' => '@branch', 'name' => 'Label', 'target' => $text?->id ?? 0, 'kind' => 'composition', 'multiplicity' => '0..1'],
    ['op' => 'add_field', 'ref' => 'bnote', 'node' => '@branch', 'name' => 'Note', 'target' => $text?->id ?? 0, 'kind' => 'composition', 'multiplicity' => '0..1'],
    ['op' => 'create_node', 'ref' => 'child', 'name' => '__rv Child', 'parent' => '@branch'],
    ['op' => 'set_list', 'node' => '@child', 'attribute' => 'summary_fields', 'members' => ['@bnote']],
    ['op' => 'create_record', 'ref' => 'c', 'node' => '@child', 'values' => ['@bname' => 'Child by name']],
]]);
$foundBelow = is_array($below) ? wp_get_ability('taxmod/query')->execute(['node' => (int) ($below['refs']['branch'] ?? 0), 'search' => 'Child by name']) : null;
check('a choice at the record\'s own node falls back to the name too', (string) ($foundBelow['records'][0]['word'] ?? '') === 'Child by name', wp_json_encode($foundBelow ?? $below));

// D-1288: who points at a record, before it is removed; and what a computed field is made of.
$refs = wp_get_ability('taxmod/referrers')->execute(['records' => [(int) $ref['alt']]]);
check('referrers name the record pointing at it', in_array((int) $ref['kid'], array_column($refs['referrers'][(int) $ref['alt']] ?? [], 'record'), true), wp_json_encode($refs));
$setl = wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'set_list', 'node' => $ref['h'], 'field' => $ref['name'], 'attribute' => 'summary_fields', 'members' => [$ref['name']]]]]);
$read     = wp_get_ability('taxmod/read-node')->execute(['node' => $ref['h'], 'records' => false]);
$composed = array_values(array_filter(is_array($read) ? $read['fields'] : [], static fn (array $f): bool => $f['relation_id'] === (int) $ref['name']))[0]['composed_of'] ?? null;
check('read-node says what a computed field is made of', $composed === [(int) $ref['name']], wp_json_encode([$setl, $composed]));

$wahl  = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'w', 'name' => '__rv Arten', 'parent' => $model->id, 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'k', 'name' => '__rv Verbrauch', 'parent' => '@w', 'class' => 'Constant'],
    ['op' => 'set_class', 'node' => '@k', 'class' => 'Choice'],
    ['op' => 'create_node', 'ref' => 'u', 'name' => '__rv Verlust', 'parent' => '@k', 'class' => 'Constant'],
]]);
check('set_class lets a constant take sub-kinds (D-1297)', is_array($wahl) && (new WpdbNodeRepository())->byId((int) $wahl['refs']['k'])->klasse === \Taxmod\Core\Model\NodeClass\Choice::class, is_wp_error($wahl) ? $wahl->get_error_message() : '');
$zurueck = is_array($wahl) ? wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'set_class', 'node' => (int) $wahl['refs']['k'], 'class' => 'Gibtsnicht']]]) : null;
check('set_class refuses an unknown class', is_wp_error($zurueck), wp_json_encode($zurueck));

$editor = (string) apply_filters(\Taxmod\WordPress\Plugin::RECORD_EDITOR_FILTER, '', (int) $ref['kid'], '/x', [['<a class="button" id="__rv-modul">Etikett</a>']]);
$leiste = strpos($editor, 'taxmod-toolbar');
check('a module\'s record editor has its toolbar above the fields, save first, the module\'s button in it (D-1308)', $leiste !== false && $leiste < strpos($editor, 'taxmod-node') && strpos($editor, '💾') < strpos($editor, '__rv-modul') && strpos($editor, '__rv-modul') < strpos($editor, 'taxmod-node'), substr($editor, 0, 400));

wp_set_current_user(0);
$denied = $send('admin_post_' . NodesScreen::RECORD_CREATE_ACTION, ['node' => (string) $ref['h'], 'name' => 'x']);
check('without the capability nothing is created', str_contains($denied, '"success":false'), $denied);

echo $bad === 0 ? "\nall green\n" : "\n$bad failed\n";
exit($bad === 0 ? 0 : 1);
