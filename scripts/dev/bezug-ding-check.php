<?php declare(strict_types=1);

/**
 * A reference may point at «any record under Model», and its picker finds the record by a server search.
 *
 *     php scripts/dev/bezug-ding-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-958](../../docs/NewConcept/90-decision-log.md), F3a, his word:** *«rest of F's ist ok»* — «Bezug Ding» on every record under
 * `Model`, with a search. *A sandbox `__bd`: (1) an aggregation onto `Model` is taken, a composition refused; (2) the search finds a record
 * by a text value and by its node's name together; (3) without a nonce it answers nothing; (4) the tree beside it (D-1011) lists the nodes and
 * a node's records.*
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

// ⚠️ *D-1011, sein Wort «Erst Text, dann Baum»: der Baum neben der Suche holt seine Knoten und die Sätze eines Knotens beim Rand.*
$frageAn = static function (string $aktion, array $get): ?array {
    $_GET = $get;
    $_REQUEST = $get;
    ob_start();

    try {
        do_action('admin_post_' . $aktion);
    } catch (RuntimeException) {
    }

    $antwort = json_decode((string) ob_get_clean(), true);

    return is_array($antwort) ? $antwort : null;
};

$knoten = $frageAn(NodesScreen::RECORD_NODES_ACTION, ['_ajax_nonce' => wp_create_nonce(NodesScreen::RECORD_NODES_ACTION)]);
$namen  = array_column($knoten['data'] ?? [], 'name', 'id');
$pruefe(($knoten['success'] ?? false) && ($namen[(int) $geruest['refs']['tr']] ?? '') === '__bd Transistorzwerg', 'the tree beside the search lists the nodes under Model');

$astNonce = wp_create_nonce(NodesScreen::RECORD_BRANCH_ACTION);
$ast      = $frageAn(NodesScreen::RECORD_BRANCH_ACTION, ['node' => (string) $geruest['refs']['tr'], '_ajax_nonce' => $astNonce]);
$saetze   = array_column($ast['data'] ?? [], 'word', 'id');
$pruefe(($ast['success'] ?? false) && str_contains((string) ($saetze[(int) $geruest['refs']['bc']] ?? ''), 'BC547QX'), 'a clicked node gives its records with their word');

$fremd = $frageAn(NodesScreen::RECORD_BRANCH_ACTION, ['node' => '1', '_ajax_nonce' => $astNonce]);
$pruefe(! ($fremd['success'] ?? false), 'a node outside Model gives nothing');

$ohne = $frageAn(NodesScreen::RECORD_BRANCH_ACTION, ['node' => (string) $geruest['refs']['tr']]);
$pruefe(! ($ohne['success'] ?? false), 'the branch without a nonce answers nothing');

// ⚠️ **[D-1587](../../docs/NewConcept/90-decision-log.md), sein Wort am 2026-11-26: «Alle ja»** — auf die Frage «Soll ein Auswahlfeld ab 500
// Sätzen auf „suchen statt Liste“ umschalten?». *Ein Sandkasten `__bd Lager`: unter der Schwelle steht das Angebot in der Seite wie bisher;
// ab ihr steht dort der Wähler der Suche mit dem Ziel als Wurzel und kein Satz des Angebots; der gewählte Satz behält sein Wort; Suche und
// Baum bleiben unter dem Ziel; ein Knoten ausserhalb von Model ist keine Wurzel.*
echo "\nAb der Schwelle suchen statt laden (D-1587)\n";

$schwelle = \Taxmod\Core\Service\Rendering::SUCHEN_AB;
// *Eine Sendung trägt höchstens 500 Änderungen, also kommen die Sätze in zweien.*
$saetze   = static fn (int|string $knoten, int $von, int $bis): array => array_map(static fn (int $i): array => ['op' => 'create_record', 'ref' => 'l' . $i, 'node' => $knoten], range($von, $bis));
$lager    = $apply->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'lager', 'name' => '__bd Lager', 'parent' => $model],
    ['op' => 'add_field', 'ref' => 'lname', 'node' => '@lager', 'target' => $text->id, 'name' => '__bd Lagername', 'multiplicity' => '0..1'],
    ['op' => 'create_node', 'ref' => 'unter', 'name' => '__bd Regal', 'parent' => '@lager'],
    ['op' => 'create_node', 'ref' => 'halter', 'name' => '__bd Halter', 'parent' => $model],
    ['op' => 'add_field', 'ref' => 'wahl', 'node' => '@halter', 'target' => '@lager', 'name' => '__bd Wahl', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
    ['op' => 'create_record', 'ref' => 'gewaehlt', 'node' => '@unter'],
    ['op' => 'set_values', 'record' => '@gewaehlt', 'values' => ['@lname' => 'Fach QX77']],
    ['op' => 'create_record', 'ref' => 'satz', 'node' => '@halter'],
    ['op' => 'set_values', 'record' => '@satz', 'values' => ['@wahl' => '@gewaehlt']],
    ...$saetze('@unter', 1, 300),
]]);
$rest     = is_wp_error($lager) ? $lager : $apply->execute(['changes' => $saetze((int) $lager['refs']['unter'], 301, $schwelle - 2)]);

if (is_wp_error($rest)) {
    $pruefe(false, 'sandbox __bd Lager — ' . $rest->get_error_message());
} else {
    require_once ABSPATH . 'wp-admin/includes/template.php';

    $seite = static function () use ($lager): string {
        // *Je Zeichnung ein frischer Rand — sonst antwortete das Gedächtnis des ersten Zeichnens.*
        $r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
        $plugin = $r->newInstanceWithoutConstructor();
        $r->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
        wp_cache_flush();
        $_GET     = ['page' => 'taxmod', 'taxmod_node' => (string) $lager['refs']['halter'], 'taxmod_preview_record' => (string) $lager['refs']['satz']];
        $_REQUEST = $_GET;

        return (string) $plugin->screen()->render();
    };
    $feld  = 'taxmod_value[' . (int) $lager['refs']['satz'] . '][' . (int) $lager['refs']['wahl'] . ']';
    $fern  = 'taxmod-record-remote taxmod-record-taken" data-under="' . (int) $lager['refs']['lager'] . '"';
    // *Unter der Schwelle ist der Wähler ein Dialog mit dem geladenen Baum (D-791); ab ihr gibt es nur den leeren und den gewählten Knopf.*
    $dialoge = static fn (string $html): int => substr_count($html, 'id="taxmod-record-dialog-');
    $knoepfe = static fn (string $html): int => substr_count($html, 'name="' . esc_attr($feld) . '" value="');

    $darunter = $seite();
    $pruefe(! str_contains($darunter, 'data-under=') && $dialoge($darunter) > 0, ($schwelle - 1) . ' records under the target: the offer stands in the page as before');

    $einer = $apply->execute(['changes' => [['op' => 'create_record', 'ref' => 'voll', 'node' => $lager['refs']['unter']]]]);
    $ab    = is_wp_error($einer) ? '' : $seite();
    $pruefe(str_contains($ab, $fern), $schwelle . ' records: the picker searches, with the target as its root');
    $pruefe($dialoge($ab) === 0 && $knoepfe($ab) === 2 && str_contains($ab, 'name="' . esc_attr($feld) . '" value="' . (int) $lager['refs']['gewaehlt'] . '" checked'), 'no loaded tree stands in the page, of the records only the chosen one');
    $pruefe($ab !== '' && strlen($ab) < strlen($darunter), 'the page is smaller than with the offer — ' . strlen($darunter) . ' bytes, then ' . strlen($ab));
    $pruefe(str_contains($ab, '>Fach QX77<'), 'the chosen record keeps its word');

    $unter   = (string) $lager['refs']['lager'];
    $antwort = $frage(['term' => 'qx77', 'under' => $unter, '_taxmod_nonce' => $nonce]);
    $pruefe(array_map(static fn (array $t): int => (int) $t['id'], $antwort['data'] ?? []) === [(int) $lager['refs']['gewaehlt']], 'the search under the target finds its record');

    $antwort = $frage(['term' => 'transistorzwerg qx', 'under' => $unter, '_taxmod_nonce' => $nonce]);
    $pruefe(($antwort['success'] ?? false) && ($antwort['data'] ?? null) === [], 'a record elsewhere under Model is not found under the target');

    $knoten = $frageAn(NodesScreen::RECORD_NODES_ACTION, ['under' => $unter, '_ajax_nonce' => wp_create_nonce(NodesScreen::RECORD_NODES_ACTION)]);
    $pruefe(array_map(static fn (array $k): array => [(int) $k['id'], (int) $k['depth']], $knoten['data'] ?? []) === [[(int) $lager['refs']['lager'], 0], [(int) $lager['refs']['unter'], 1]], 'the tree lists the target and what lies under it, nothing else');

    $fremd = $frage(['term' => 'qx77', 'under' => '1', '_taxmod_nonce' => $nonce]);
    $pruefe(! ($fremd['success'] ?? false), 'a root outside Model answers nothing');
}

exit($fehler === 0 ? 0 : 1);
