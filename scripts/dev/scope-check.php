<?php declare(strict_types=1);

/**
 * The node page scoped to one branch (D-1574, Stefan 2026-11-21: «du musst das halt nur auf den/die materilast/äste
 * einschränken»), as a failing check: with `taxmod_scope` the tree starts at that node and shows it as its first row,
 * nothing beside it and no trash; links carry the scope; a scope that is no node, the trash or below it shows the whole tree;
 * no act leaves the branch: a move out of it, an act beside it and parking the branch itself are refused; embedded in a module's
 * page (`embeddedIn`, filter `taxmod_node_screen`) links come back to that page only when it is registered as a host; a row
 * carries the same buttons in both trees (add, rename, move), the «+» named for its context.
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
// ⚠️ *Ein zweites Kind, damit «Kind» in beiden Bäumen eine Zeile nach sich hat — sonst liefe sein Ausschnitt im Ast bis ans Seitenende.*
$kind2   = $editor->createNode('__sc Kind 2', $ast->id);
$enkel   = $editor->createNode('__sc Enkel', $kind2->id);
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

echo "\n== derselbe Knopf in beiden Bäumen (Prüftor zu Live-Check 15, D-1511 Regel 3) ==\n";
$ganzerBaum = $draw(null);
$knoepfe    = static function (string $html, int $id): array {
    // *Die Zeile reicht von ihrer Id bis zur nächsten Zeile des Baums.*
    preg_match('/id="taxmod-node-' . $id . '".*?(?=class="taxmod-tree-node|$)/s', $html, $zeile);
    preg_match_all('/dashicons-([a-z0-9-]+)/', $zeile[0] ?? '', $icons);

    return array_values(array_unique($icons[1]));
};
$imAst  = $knoepfe($html, $kind->id);
$imBaum = $knoepfe($ganzerBaum, $kind->id);
$say($imAst !== [] && $imAst === $imBaum, 'die Zeile trägt im Ast dieselben Symbole wie im ganzen Baum (Ast: ' . implode(' ', $imAst) . ' · Baum: ' . implode(' ', $imBaum) . ')');
$say(in_array('edit', $imAst, true) && in_array('move', $imAst, true), 'Umbenennen und Verschieben stehen in der Zeile');
$say(str_contains($html, esc_attr__('Add a subgroup under this group', 'taxmod')) && ! str_contains($ganzerBaum, esc_attr__('Add a subgroup under this group', 'taxmod'))
    && str_contains($ganzerBaum, esc_attr__('Add a child under this node', 'taxmod')), 'das «+» heisst im Ast «Untergruppe anlegen», im ganzen Baum «Kind anlegen»');
$say(str_contains($html, 'id="taxmod-rename-' . $kind->id . '"') && str_contains($html, 'class="taxmod-pick-submit" hidden name="do" value="move"'), 'Umbenennen öffnet seinen Dialog, Verschieben den Wahlbaum mit dem Akt dahinter');

echo "\n== in der Seite eines Moduls (Live-Check 15) ==\n";
unset($_GET['taxmod_scope']);
$_GET['taxmod_collapsed'] = 'none';
$host = 'taxmod-__sc-host';
$say(! str_contains($plugin->screen()->embeddedIn($ast->id, $host)->render(), 'page=' . $host), 'eine nicht angemeldete Seite ist kein Ziel, auch mit taxmod- davor');
add_filter(\Taxmod\WordPress\Plugin::NODE_SCREEN_HOSTS_FILTER, static fn (array $hosts): array => [...$hosts, $host]);
$eingebettet = $plugin->screen()->embeddedIn($ast->id, $host)->render();
$say($row($eingebettet, $kind->id) && ! $row($eingebettet, $daneben->id), 'eingebettet gilt die Eingrenzung ohne Parameter in der Adresse');
$say(str_contains($eingebettet, 'page=' . $host), 'die Links führen auf die angemeldete Modulseite');
$_GET['taxmod_host'] = $host;
$say(! str_contains($plugin->screen()->render(), 'page=' . $host), 'ohne Eingrenzung führt kein Link auf die Modulseite');
unset($_GET['taxmod_host']);
$say(has_filter(\Taxmod\WordPress\Plugin::NODE_SCREEN_FILTER) !== false, 'der Filter für Modulseiten ist angemeldet');

echo "\n== ohne oder mit falscher Eingrenzung der ganze Baum ==\n";
$ganz = $draw(null);
$say($row($ganz, $daneben->id) && $row($ganz, $ast->id), 'ohne Parameter steht beides da');
$say($row($draw('999999999'), $daneben->id), 'eine Id ohne Knoten grenzt nicht ein');
$say($row($draw((string) $fw->trash()->id), $daneben->id), 'der Papierkorb grenzt nicht ein');
$say($row($draw('abc'), $daneben->id), 'keine Zahl grenzt nicht ein');

echo "\n== Reiter im eingegrenzten Detail (D-1574, Prüftor C) ==\n";
$reiter = static fn (array $tabs, int $node, int $scope): array => $node > 0 && $scope > 0
    ? [...$tabs, ['key' => '__sc', 'label' => '__sc Materialien', 'html' => '<p id="__sc-tab">' . $node . '/' . $scope . '</p>', 'count' => 3]] : $tabs;
add_filter(\Taxmod\WordPress\Plugin::SCOPED_TABS_FILTER, $reiter, 10, 3);
$_GET = ['taxmod_scope' => (string) $ast->id, 'taxmod_node' => (string) $kind->id, 'taxmod_collapsed' => 'none'];
$erst = $plugin->screen()->render();
$say(str_contains($erst, 'nav-tab nav-tab-active" href="') && str_contains($erst, '__sc Materialien') && str_contains($erst, '<p id="__sc-tab">' . $kind->id . '/' . $ast->id . '</p>'),
    'der Reiter des Moduls steht zuerst und bekommt Knoten und Ast');
$say(strpos($erst, '__sc Materialien') < strpos($erst, '>' . esc_html__('Properties', 'taxmod') . '<'), '«Eigenschaften» folgt als Reiter des Modellers');
$say(! str_contains($erst, 'taxmod-records-block'), 'die Satzliste des Modellers steht im Ast nicht da — die Materialien sind der Reiter des Moduls');
$_GET['taxmod_scoped_tab'] = 'properties';
$eigen = $plugin->screen()->render();
$say(! str_contains($eigen, '<p id="__sc-tab">') && str_contains($eigen, 'taxmod_scoped_tab=properties'), '«Eigenschaften» zeigt die Felder statt des Modulreiters, und die Links behalten den Reiter');
$_GET['taxmod_scoped_tab'] = 'nonsense';
$say(str_contains($plugin->screen()->render(), '<p id="__sc-tab">'), 'ein unbekannter Reiter fällt auf den ersten zurück');
remove_filter(\Taxmod\WordPress\Plugin::SCOPED_TABS_FILTER, $reiter, 10);
$_GET = [];

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
$say(str_contains($post('trash_node', $kind2->id), __('Only an empty group can be parked: move its materials and subgroups away first', 'taxmod'))
    && ! in_array($fw->trash()->id, $editor->find($kind2->id)->ancestorIds(), true), 'D-1574 (D): eine Gruppe mit Untergruppe wird nicht geparkt, mit Grund');
$_POST = $_REQUEST = [];
$_GET  = ['taxmod_scope' => (string) $ast->id, 'taxmod_collapsed' => 'none'];
$leer  = $plugin->screen()->render();
$parkt = static fn (string $html, int $id): bool => preg_match('/id="taxmod-node-' . $id . '".*?value="trash_node"[^>]*>/s', $html, $m) === 1 && ! str_contains($m[0], ' disabled');
$say($parkt($leer, $kind->id) && ! $parkt($leer, $kind2->id), 'D-1574 (D): im Ast ist der Papierkorb nur an der leeren Gruppe frei, an der mit Untergruppe gesperrt');
$_GET = ['taxmod_collapsed' => 'none'];
$say($parkt($plugin->screen()->render(), $kind2->id), 'im ganzen Baum bleibt er frei');
$_GET = [];

echo $failed === 0 ? "\nscope-check: ok\n" : "\nscope-check: {$failed} FAIL\n";
$finished = true;
ob_end_flush();
exit($failed === 0 ? 0 : 1);
