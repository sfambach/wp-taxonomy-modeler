<?php declare(strict_types=1);

/**
 * The node page scoped to one branch (D-1574, Stefan 2026-11-21: «du musst das halt nur auf den/die materilast/äste
 * einschränken»), as a failing check: with `taxmod_scope` the tree starts at that node and shows it as its first row,
 * nothing beside it and no trash; links carry the scope; a scope that is no node, the trash or below it shows the whole tree;
 * no act leaves the branch: a move out of it, an act beside it and parking the branch itself are refused; embedded in a module's
 * page (`embeddedIn`, filter `taxmod_node_screen`) links come back to that page, never to one outside Taxmod.
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

// ⚠️ *Gepuffert wie `act-failure`: sonst sind die Kopfzeilen gesendet und `ListPage::fail()` zeigt seine Fehlerseite statt umzuleiten.
// Und ein Abbruch mittendrin (wp_die, ein Fehler) zählt als FAIL, nicht als stilles Ende mit 0.*
ob_start();
$finished = false;
register_shutdown_function(static function () use (&$finished): void {
    if (! $finished) {
        echo "\nscope-check: FAIL — abgebrochen, bevor alle Zusagen geprüft waren\n";
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        exit(1);
    }
});

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

$say(str_contains($html, '>' . esc_html__('+ Subgroup', 'taxmod') . '<'), 'die Zeile zeigt «+ Untergruppe» als Wort (Live-Check 15)');

echo "\n== in der Seite eines Moduls (Live-Check 15) ==\n";
unset($_GET['taxmod_scope']);
$_GET['taxmod_collapsed'] = 'none';
$eingebettet = $plugin->screen()->embeddedIn($ast->id, 'taxmod-__sc-host')->render();
$say($row($eingebettet, $kind->id) && ! $row($eingebettet, $daneben->id), 'eingebettet gilt die Eingrenzung ohne Parameter in der Adresse');
$say(str_contains($eingebettet, 'page=taxmod-__sc-host') && ! str_contains(str_replace('page=taxmod-__sc-host', '', $eingebettet), 'page=taxmod&amp;taxmod_collapsed'), 'Faltlinks führen auf die Modulseite');
$say(! str_contains($plugin->screen()->embeddedIn($ast->id, 'options-general')->render(), 'page=options-general'), 'eine Seite ausserhalb von Taxmod ist kein Ziel');
$_GET['taxmod_host'] = 'taxmod-__sc-host';
$say(! str_contains($plugin->screen()->render(), 'page=taxmod-__sc-host'), 'ohne Eingrenzung führt kein Link auf die Modulseite');
unset($_GET['taxmod_host']);
$say(has_filter(\Taxmod\WordPress\Plugin::NODE_SCREEN_FILTER) !== false, 'der Filter für Modulseiten ist angemeldet');

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
    try {
        $plugin->screen()->handlePost();
    } catch (ScopeStop) {
    }

    return $went === '' ? '' : \Taxmod\WordPress\Admin\ListPage::messages();
};
$parent = static fn (int $id): ?int => $editor->find($id)?->parentId();

$say(str_contains($post('move', $kind->id, $daneben->id), __('A node cannot be moved out of the branch this page shows.', 'taxmod')) && $parent($kind->id) === $ast->id,
    'ein Verschieben aus dem Ast heraus wird abgelehnt und das Kind bleibt');
$say(str_contains($post('rename', $daneben->id), __('This node lies outside the branch this page shows.', 'taxmod')), 'ein Akt an einem Knoten neben dem Ast wird abgelehnt');
$say(str_contains($post('trash', $ast->id), __('This page shows one branch only; do this in the whole model tree.', 'taxmod')) && $editor->find($ast->id) !== null
    && ! in_array($fw->trash()->id, $editor->find($ast->id)->ancestorIds(), true), 'den Ast selbst parkt man hier nicht');
$_POST = $_REQUEST = [];

echo $failed === 0 ? "\nscope-check: ok\n" : "\nscope-check: {$failed} FAIL\n";
$finished = true;
ob_end_flush();
exit($failed === 0 ? 0 : 1);
