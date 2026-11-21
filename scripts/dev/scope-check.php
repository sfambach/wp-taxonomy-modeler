<?php declare(strict_types=1);

/**
 * The node page scoped to one branch (D-1574, Stefan 2026-11-21: «du musst das halt nur auf den/die materilast/äste
 * einschränken»), as a failing check: with `taxmod_scope` the tree starts at that node and shows it as its first row,
 * nothing beside it and no trash; links carry the scope; a scope that is no node, the trash or below it shows the whole tree;
 * no act leaves the branch: a move out of it, an act beside it and parking the branch itself are refused.
 *
 *     WP_ROOT=/path/to/wordpress php scripts/dev/scope-check.php
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — siehe `lib/no-write.php`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

wp_set_current_user(1);

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\{SeededFrameworkNodes, WpdbChangelog, WpdbNodeRepository, WpdbRelationRepository};
use Taxmod\WordPress\SystemClock;

register_shutdown_function(static fn (): int => \Taxmod\WordPress\Persistence\Schema::forgetOrphanLabels());

$nodes     = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$fw        = new SeededFrameworkNodes($nodes, $relations, $log);
$editor    = new ModelEditor($nodes, $relations, $fw, $log);

$failed = 0;
$say    = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);
    $failed += $ok ? 0 : 1;
};

$ast     = $editor->createNode('__sc Ast', $fw->rootOf(Branch::Model)->id);
$kind    = $editor->createNode('__sc Kind', $ast->id);
$daneben = $editor->createNode('__sc Daneben', $fw->rootOf(Branch::Model)->id);

$r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');

$row  = static fn (string $html, int $id): bool => str_contains($html, 'id="taxmod-node-' . $id . '"');
$draw = static function (?string $scope) use ($plugin): string {
    unset($_GET['taxmod_node'], $_GET['taxmod_scope']);
    $_GET['taxmod_collapsed'] = 'none';

    if ($scope !== null) {
        $_GET['taxmod_scope'] = $scope;
    }

    return $plugin->screen()->render();
};

echo "== eingegrenzt auf den Ast ==\n";
$html = $draw((string) $ast->id);
$say($row($html, $ast->id), 'der Ast selbst ist die erste Zeile');
$say($row($html, $kind->id), 'sein Kind steht darunter');
$say(! $row($html, $daneben->id), 'was neben dem Ast liegt, steht nicht da');
$say(! str_contains($html, 'class="taxmod-trash"'), 'kein Papierkorb im eingegrenzten Baum');
$say(str_contains($html, 'taxmod_scope=' . $ast->id), 'die Links tragen die Eingrenzung');
$say(preg_match('/data-taxmod-carry="[^"]*\btaxmod_scope\b/', $html) === 1, 'die Seite führt sie im Skript mit');

echo "\n== ohne oder mit falscher Eingrenzung der ganze Baum ==\n";
$ganz = $draw(null);
$say($row($ganz, $daneben->id) && $row($ganz, $ast->id), 'ohne Parameter steht beides da');
$say($row($draw('999999999'), $daneben->id), 'eine Id ohne Knoten grenzt nicht ein');
$say($row($draw((string) $fw->trash()->id), $daneben->id), 'der Papierkorb grenzt nicht ein');
$say($row($draw('abc'), $daneben->id), 'keine Zahl grenzt nicht ein');

echo "\n== kein Akt verlässt den Ast (Prüftor 21.11.) ==\n";
final class ScopeStop extends RuntimeException
{
}

$went = '';
add_filter('wp_redirect', static function (string $to) use (&$went): never {
    $went = $to;
    throw new ScopeStop();
}, 1);

$aktion = (string) (new ReflectionClass(\Taxmod\WordPress\Admin\NodesScreen::class))->getConstant('ACTION');
$post   = static function (string $do, int $id, int $target = 0) use ($plugin, $aktion, $ast, &$went): string {
    $went     = '';
    $_GET     = [];
    $_POST    = wp_slash(['action' => $aktion, 'id' => (string) $id, 'do' => $do, 'target' => (string) $target, 'taxmod_scope' => (string) $ast->id,
        '_taxmod_nonce' => wp_create_nonce($aktion . '_' . $id)]);
    $_REQUEST = $_POST;
    ob_start();
    try {
        $plugin->screen()->handlePost();
    } catch (ScopeStop) {
    }
    ob_end_clean();
    parse_str((string) parse_url($went, PHP_URL_QUERY), $teile);

    return rawurldecode((string) ($teile['taxmod_message'] ?? ''));
};
$parent = static fn (int $id): ?int => $editor->find($id)?->parentId();

$say($post('move', $kind->id, $daneben->id) === __('A node cannot be moved out of the branch this page shows.', 'taxmod') && $parent($kind->id) === $ast->id,
    'ein Verschieben aus dem Ast heraus wird abgelehnt und das Kind bleibt');
$say($post('rename', $daneben->id) === __('This node lies outside the branch this page shows.', 'taxmod'), 'ein Akt an einem Knoten neben dem Ast wird abgelehnt');
$say($post('trash', $ast->id) === __('This page shows one branch only; do this in the whole model tree.', 'taxmod') && $editor->find($ast->id) !== null
    && ! in_array($fw->trash()->id, $editor->find($ast->id)->ancestorIds(), true), 'den Ast selbst parkt man hier nicht');
$_POST = $_REQUEST = [];

echo $failed === 0 ? "\nscope-check: ok\n" : "\nscope-check: {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
