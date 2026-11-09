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

$door = new ModelDoor();
$door->markForReview([$ref['alt']]);
check('a module marks a record it made with what it could derive', RecordReviews::among([$ref['alt']]) === [$ref['alt']]);
RecordReviews::clear($ref['alt']);

$pointing = $door->referrersOf([$ref['alt'], $ref['kid']]);
check('the door names who points at a record, through any field', $pointing === [$ref['alt'] => [$ref['kid']]], wp_json_encode($pointing));

// D-1270: removing a record empties only a row that owns it; a row that merely points at it stays.
$removed = wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'remove_record', 'record' => $ref['alt']]]]);
check('a record another points at by aggregation can be removed', is_array($removed), is_wp_error($removed) ? $removed->get_error_message() : '');
check('the pointing row is left as it was', ($door->referrersOf([$ref['alt']])[$ref['alt']] ?? []) === [$ref['kid']], wp_json_encode($door->referrersOf([$ref['alt']])));

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

wp_set_current_user(0);
$denied = $send('admin_post_' . NodesScreen::RECORD_CREATE_ACTION, ['node' => (string) $ref['h'], 'name' => 'x']);
check('without the capability nothing is created', str_contains($denied, '"success":false'), $denied);

echo $bad === 0 ? "\nall green\n" : "\n$bad failed\n";
exit($bad === 0 ? 0 : 1);
