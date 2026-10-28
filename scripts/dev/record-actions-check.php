<?php declare(strict_types=1);

/**
 * A module offers an action on one record, and the record's page draws it.
 *
 *     php scripts/dev/record-actions-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-998](../../docs/NewConcept/90-decision-log.md), his word:** *«dein 2. vorschalg hört sich gut an»* — a button
 * «Bei JLC nachbestellen» at the revision, built by the JLC module. *A sandbox `__ra`: (1) an action given through the filter
 * stands on the record's page, escaped; (2) one without an address is left out; (3) a new tab only when asked for.*
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
use Taxmod\WordPress\Plugin;

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
    ['op' => 'create_node', 'ref' => 'rev', 'name' => '__ra Revision', 'parent' => $model],
    ['op' => 'add_field', 'ref' => 'ver', 'node' => '@rev', 'target' => $text->id, 'name' => '__ra Version', 'multiplicity' => '0..1'],
    ['op' => 'create_record', 'ref' => 'r1', 'node' => '@rev'],
    ['op' => 'set_values', 'record' => '@r1', 'values' => ['@ver' => '1.0']],
]]);

if (! is_array($geruest)) {
    echo '  FAIL sandbox — ' . (is_wp_error($geruest) ? $geruest->get_error_message() : '') . "\n";
    exit(1);
}

$knoten = (int) $geruest['refs']['rev'];
$satz   = (int) $geruest['refs']['r1'];
$gefragt = [];

add_filter(NodesScreen::RECORD_ACTIONS_FILTER, static function (array $actions, int $nodeId, int $recordId) use (&$gefragt): array {
    $gefragt = [$nodeId, $recordId];
    $actions[] = ['label' => 'Bei JLC <nachbestellen>', 'url' => 'https://example.org/reorder?r=' . $recordId, 'title' => 'Werte zeigen', 'new_tab' => true];
    $actions[] = ['label' => '__ra ohne Adresse', 'url' => ''];
    $actions[] = ['label' => '__ra gleicher Tab', 'url' => 'https://example.org/same'];

    return $actions;
}, 10, 3);

$_GET['page']                  = 'taxmod-nodes';
$_GET['taxmod_node']           = (string) $knoten;
$_GET['taxmod_preview_record'] = (string) $satz;

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, __FILE__);
$seite = $plugin->screen()->render();

$pruefe($gefragt === [$knoten, $satz], 'the filter is asked with the node and the record shown');
$pruefe(str_contains($seite, 'href="https://example.org/reorder?r=' . $satz . '" title="Werte zeigen" target="_blank" rel="noopener">Bei JLC &lt;nachbestellen&gt;</a>'), 'the action stands on the page, escaped, in a new tab');
$pruefe(! str_contains($seite, '__ra ohne Adresse'), 'an action without an address is left out');
$pruefe((bool) preg_match('~href="https://example.org/same">__ra gleicher Tab</a>~', $seite), 'without new_tab it opens in the same tab');

exit($fehler === 0 ? 0 : 1);
