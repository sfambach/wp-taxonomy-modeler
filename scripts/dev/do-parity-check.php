<?php declare(strict_types=1);

/**
 * Every `do` the node page knew still does something, and an unknown one is still refused (renderer plan step 4, D-1424,
 * [D-633](../../docs/NewConcept/90-decision-log.md)): the `match ($do)` became one listener per name.
 *
 * ⚠️ *Two halves. **Heard**: each of the 39 names from the `match` as it stood on 2026-11-17 is posted and does not come back
 * as «Unknown action.» — and the names the page registers are exactly these plus the ones a later decision added (`ADDED_ARMS`), no more. **Same result**: four acts whose effect
 * is plain (rename, add a child and stand on it, a new record, duplicate and stand on the copy) are posted and their effect
 * read back. What each of the others does is guarded by the checks that already post them (concurrent-save, claimed-field,
 * einstellungen, pakete …).*
 *
 *     php scripts/dev/do-parity-check.php
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;

wp_set_current_user(1);

final class DoParityStop extends RuntimeException
{
}

/** The arms of `match ($do)` in NodesScreen on main 7f7ef9b, 2026-11-17 — the list the listeners must keep. */
const FORMER_ARMS = [
    'add_child', 'toggle_hide', 'duplicate', 'rename', 'move', 'field_up', 'field_down', 'up', 'down', 'restore', 'clear_trash',
    'trash', 'trash_with_uses', 'trash_node', 'add_field', 'retarget_field', 'remove_field', 'field_to_parent', 'field_to_children',
    'restore_field', 'toggle_field_hide', 'toggle_field_settings', 'duplicate_field', 'put_setting', 'put_multiplicity', 'add_record',
    'add_example', 'save_record', 'save_record_anyway', 'filter_records', 'clear_filter', 'add_part', 'add_parts', 'remove_part',
    'unlink_part', 'insert_part', 'duplicate_record', 'delete_record', 'move_record',
];

// Names a later decision added; what each does is guarded where it was added (add_variant: varianten-check, D-1478).
const ADDED_ARMS = ['add_variant'];

$failed = 0;

$say = static function (bool $ok, string $what, string $detail = '') use (&$failed): void {
    printf("  %-4s %s%s\n", $ok ? 'ok' : 'FAIL', $what, $ok || $detail === '' ? '' : ' — ' . $detail);

    if (! $ok) {
        ++$failed;
    }
};

$r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');

$editor = $plugin->editor();
$fw     = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin);
$aktion = (string) (new ReflectionClass(NodesScreen::class))->getConstant('ACTION');
$text   = (new WpdbNodeRepository())->byImplementations([TextType::class])[TextType::class] ?? null;

$umgeleitet = '';

add_filter('wp_redirect', static function (string $location) use (&$umgeleitet): string {
    $umgeleitet = $location;

    throw new DoParityStop();
});

/** @return array{message: string, node: int} what the page said, and where it stands afterwards */
$abschicken = static function (int $knoten, string $do, array $post = []) use ($plugin, $aktion, &$umgeleitet): array {
    $post += ['action' => $aktion, 'id' => (string) $knoten, 'do' => $do, '_taxmod_nonce' => wp_create_nonce($aktion . '_' . $knoten)];
    $_POST      = wp_slash($post);
    $_REQUEST   = $_POST;
    $umgeleitet = '';

    ob_start();

    try {
        $plugin->screen()->handlePost();
    } catch (DoParityStop) {
    } catch (\Throwable $fehler) {
        ob_end_clean();

        return ['message' => 'THROWN ' . get_class($fehler) . ': ' . $fehler->getMessage(), 'node' => 0];
    }

    ob_end_clean();
    parse_str((string) parse_url($umgeleitet, PHP_URL_QUERY), $teile);

    return ['message' => rawurldecode((string) ($teile['taxmod_message'] ?? '')), 'node' => (int) ($teile['taxmod_node'] ?? 0)];
};

echo "== every former name is heard ==\n";

$quelle    = (string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Admin/NodesScreen.php');
$konstante = (string) (new ReflectionClass(NodesScreen::class))->getConstant('TOGGLE_ROW_SETTINGS');
preg_match_all("/listen\\(ControlPressed::class,.*?, ('([a-z_]+)'|self::TOGGLE_ROW_SETTINGS)\\);$/ms", $quelle, $treffer);
$angemeldet = array_map(static fn (string $n): string => $n === '' ? $konstante : $n, $treffer[2]);
sort($angemeldet);
$frueher = [...FORMER_ARMS, ...ADDED_ARMS];
sort($frueher);

$say(count($angemeldet) > 0 && $angemeldet === $frueher, 'the page registers exactly the ' . count(FORMER_ARMS) . ' former names and the ' . count(ADDED_ARMS) . ' added since', implode(', ', array_merge(array_diff($frueher, $angemeldet), array_map(static fn ($n) => '+' . $n, array_diff($angemeldet, $frueher)))));

$unbekannt = [];

foreach (FORMER_ARMS as $do) {
    $knoten = $editor->createNode('__dp ' . $do, $fw->rootOf(Branch::Model)->id);
    // *A valid multiplicity, so `put_multiplicity` is not refused by its own input check, which also says «Unknown action.».*
    $antwort = $abschicken($knoten->id, $do, ['setting_key' => 'wie_oft', 'taxmod_setting' => ['wie_oft' => '0..*']]);

    if ($antwort['message'] === __('Unknown action.', 'taxmod') || str_starts_with($antwort['message'], 'THROWN')) {
        $unbekannt[] = $do . ' (' . $antwort['message'] . ')';
    }
}

$say($unbekannt === [], 'none of them comes back as «Unknown action.»', implode('; ', $unbekannt));

$fremd = $editor->createNode('__dp fremd', $fw->rootOf(Branch::Model)->id);
$say($abschicken($fremd->id, 'no_such_act')['message'] === __('Unknown action.', 'taxmod'), 'an unknown name is still refused');

echo "\n== four acts, same result ==\n";

$knoten = $editor->createNode('__dp Knoten', $fw->rootOf(Branch::Model)->id);
$editor->addField($knoten->id, (int) $text?->id, '__dp Titel');

$abschicken($knoten->id, 'rename', ['name' => '__dp umbenannt']);
$say($editor->find($knoten->id)?->name === '__dp umbenannt', 'rename renames');

$kind = $abschicken($knoten->id, 'add_child', ['name' => '__dp Kind']);
$say($kind['node'] > 0 && $editor->find($kind['node'])?->parentNodeId === $knoten->id, 'add_child makes a child and stands on it', (string) $kind['node']);

global $wpdb;
$saetze = static fn (int $id): int => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('node_records') . ' WHERE node_id = %d', $id));
$vorher = $saetze($knoten->id);
$abschicken($knoten->id, 'add_record');
$say($saetze($knoten->id) === $vorher + 1, 'add_record adds one record');

$kopie = $abschicken($knoten->id, 'duplicate');
$say($kopie['node'] > 0 && $kopie['node'] !== $knoten->id && $editor->find($kopie['node'])?->name === '__dp umbenannt', 'duplicate copies and stands on the copy', (string) $kopie['node']);

echo $failed === 0 ? "\nAll good.\n" : "\n$failed FAILED\n";

exit($failed === 0 ? 0 : 1);
