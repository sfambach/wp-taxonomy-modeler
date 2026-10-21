<?php declare(strict_types=1);

/**
 * A reference may point at «any record under Model», and its picker finds the record by a server search.
 *
 *     php scripts/dev/bezug-ding-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-958](../../docs/NewConcept/90-decision-log.md), F3a, his word:** *«rest of F's ist ok»* — «Bezug Ding» on every record under
 * `Model`, with a search. *A sandbox `__bd`: (1) an aggregation onto `Model` is taken, a composition refused; (2) the search finds a record
 * by a text value and by its node's name together; (3) without a nonce it answers nothing.*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **No check writes into the owner's model** — the bracket rolls everything back at the end.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\Admin\NodesScreen;
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
    ['op' => 'create_node', 'ref' => 'mat', 'name' => '__bd Material', 'parent' => $model],
    ['op' => 'add_field', 'ref' => 'ding', 'node' => '@mat', 'target' => $model, 'name' => '__bd Bezug Ding', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
    ['op' => 'create_node', 'ref' => 'tr', 'name' => '__bd Transistorzwerg', 'parent' => $model],
    ['op' => 'add_field', 'ref' => 'typ', 'node' => '@tr', 'target' => $text->id, 'name' => '__bd Typ', 'multiplicity' => '0..1'],
    ['op' => 'create_record', 'ref' => 'bc', 'node' => '@tr'],
    ['op' => 'set_values', 'record' => '@bc', 'values' => ['@typ' => 'BC547QX']],
]]);

$pruefe(is_array($geruest), 'an aggregation onto Model is taken' . (is_wp_error($geruest) ? ' — ' . $geruest->get_error_message() : ''));

if (! is_array($geruest)) {
    exit(1);
}

$teil = $apply->execute(['changes' => [
    ['op' => 'add_field', 'node' => $geruest['refs']['mat'], 'target' => $model, 'name' => '__bd Teil', 'kind' => 'composition', 'multiplicity' => '0..1'],
]]);
$pruefe(is_wp_error($teil), 'a composition onto Model is refused');

// *Die Antwort geht über wp_send_json/wp_die — als Ajax gerechnet, damit wp_die eine Ausnahme wirft statt den Lauf zu beenden.*
add_filter('wp_doing_ajax', '__return_true');
add_filter('wp_die_ajax_handler', static fn () => static function ($nachricht): void {
    throw new RuntimeException(is_scalar($nachricht) ? (string) $nachricht : '');
});

$frage = static function (array $get): ?array {
    $_GET = $get;
    $_REQUEST = $get;
    ob_start();

    try {
        do_action('admin_post_' . NodesScreen::RECORD_SEARCH_ACTION);
    } catch (RuntimeException) {
    }

    $antwort = json_decode((string) ob_get_clean(), true);

    return is_array($antwort) ? $antwort : null;
};

$nonce   = wp_create_nonce(NodesScreen::RECORD_SEARCH_ACTION);
$antwort = $frage(['term' => 'transistorzwerg qx', '_taxmod_nonce' => $nonce]);
$ids     = array_map(static fn (array $t): int => (int) $t['id'], $antwort['data'] ?? []);
$pruefe(($antwort['success'] ?? false) && in_array((int) $geruest['refs']['bc'], $ids, true), 'the search finds the record by node name and value together');

$antwort = $frage(['term' => 'zzqqnichtda', '_taxmod_nonce' => $nonce]);
$pruefe(($antwort['success'] ?? false) && ($antwort['data'] ?? null) === [], 'a word nothing holds finds nothing');

$antwort = $frage(['term' => 'transistorzwerg']);
$pruefe(! ($antwort['success'] ?? false), 'without a nonce it answers nothing');

exit($fehler === 0 ? 0 : 1);
