<?php declare(strict_types=1);

/**
 * The node page scoped to one branch (D-1574, Stefan 2026-11-21: «du musst das halt nur auf den/die materilast/äste
 * einschränken»), as a failing check: with `taxmod_scope` the tree starts at that node and shows it as its first row,
 * nothing beside it and no trash; links carry the scope; a scope that is no node, the trash or below it shows the whole tree.
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

echo $failed === 0 ? "\nscope-check: ok\n" : "\nscope-check: {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
