<?php declare(strict_types=1);

/**
 * A module asks for the node chooser and gets the modeller's own: a dialog, a search field at the tree, one branch, exclusions.
 *
 *     php scripts/dev/node-chooser-check.php [path/to/wordpress]
 *
 * ⚠️ **[D-1075](../../docs/NewConcept/90-decision-log.md), his word:** *«wir hatte in tax mal festegelgt dass es immer die gleich ansicht
 * ist der tax chooser evtl müssen wir den noch erweitern um mehrer äste ein und auszuschlissen aber der ist immer die ansicht»*.
 * *A sandbox `__nc`: a group with two children; the chooser rooted at the group, one child excluded.*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

// ⚠️ **No check writes into the owner's model** — the bracket rolls everything back at the end.
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Plugin;

wp_set_current_user(1);

if (! function_exists('wp_get_ability')) {
    echo "  SKIP this WordPress has no Abilities API\n";
    exit(0);
}

$apply = wp_get_ability('taxmod/apply');
$model = (int) get_option('taxmod_branch_model_id', 0);

if ($apply === null || $model === 0) {
    echo "  FAIL scaffold missing (apply, Model)\n";
    exit(1);
}

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

$geruest = $apply->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'g', 'name' => '__nc Gruppe', 'parent' => $model],
    ['op' => 'create_node', 'ref' => 'a', 'name' => '__nc Kabel', 'parent' => '@g'],
    ['op' => 'create_node', 'ref' => 'b', 'name' => '__nc Verbotenes', 'parent' => '@g'],
]]);

$pruefe(is_array($geruest), 'sandbox' . (is_wp_error($geruest) ? ' — ' . $geruest->get_error_message() : ''));

if (! is_array($geruest)) {
    exit(1);
}

$html = (string) apply_filters(Plugin::NODE_CHOOSER_FILTER, '', [
    'name'        => 'material[gruppe]',
    'root'        => $geruest['refs']['g'],
    'exclude'     => [$geruest['refs']['b']],
    'preselected' => $geruest['refs']['a'],
    'form'        => 'lager-form',
]);

$pruefe(str_contains($html, 'taxmod-dialog'), 'a dialog, as in the modeller');
$pruefe(str_contains($html, 'taxmod-tree-filter'), 'with the search field at the tree');
$pruefe(str_contains($html, '__nc Kabel') && ! str_contains($html, '__nc Verbotenes'), 'an excluded branch is left out');
$pruefe((bool) preg_match('~type="radio"[^>]*name="material\[gruppe\]"~', $html) || (bool) preg_match('~name="material\[gruppe\]"[^>]*type="radio"~', $html), 'the choice goes to the module\'s field name');
$pruefe(str_contains($html, 'form="lager-form"'), 'and to the module\'s form');
$pruefe((bool) preg_match('~value="' . $geruest['refs']['a'] . '"[^>]*checked|checked[^>]*value="' . $geruest['refs']['a'] . '"~', $html), 'the preselected node is chosen');

// ⚠️ *[D-1447](../../docs/NewConcept/90-decision-log.md): asked with `shared`, the tree stands once per page — each chooser holds a
// placeholder with its own choice, and the module takes the tree as a template. Not asked, nothing changes.*
$geteilt = static fn (string $name, int $wahl, bool $shared = true, string $form = 'lager-form'): string => (string) apply_filters(Plugin::NODE_CHOOSER_FILTER, '', [
    'name' => $name, 'root' => $geruest['refs']['g'], 'preselected' => $wahl, 'form' => $form, 'shared' => $shared,
]);
$stueck  = static fn (string $html): string => preg_match('/data-taxmod-body="(b[0-9a-f]{12})"/', $html, $m) === 1 ? $m[1] : '';
$eins    = $geteilt('gruppe[1]', $geruest['refs']['a']);
$zwei    = $geteilt('gruppe[2]', $geruest['refs']['b']);
$pruefe(! str_contains($eins, 'taxmod-tree-row') && str_contains($eins, 'name="gruppe[1]"') && str_contains($eins, 'value="' . $geruest['refs']['a'] . '"'),
    'shared: the chooser holds a placeholder with its choice, not the tree');
$pruefe($stueck($eins) !== '' && $stueck($eins) === $stueck($zwei), 'shared: two choosers with different choices name the same tree');
$vorlagen = (string) apply_filters(Plugin::SHARED_TEMPLATES_FILTER, '');
$pruefe(substr_count($vorlagen, '<template class="taxmod-shared-body"') === 1 && str_contains($vorlagen, 'data-taxmod-body="' . $stueck($eins) . '"'),
    'shared: the module gets that tree once, as a template');
$pruefe((string) apply_filters(Plugin::SHARED_TEMPLATES_FILTER, '') === '', 'shared: a template is handed out once');
$pruefe(str_contains($geteilt('gruppe[3]', $geruest['refs']['a'], true, ''), 'taxmod-tree-row'), 'shared without a form: the tree stays in the piece');
$pruefe(str_contains($geteilt('gruppe[4]', $geruest['refs']['a'], false), 'taxmod-tree-row') && (string) apply_filters(Plugin::SHARED_TEMPLATES_FILTER, '') === '',
    'not asked: the tree stays in the piece, as before');

wp_set_current_user(0);
$pruefe((string) apply_filters(Plugin::NODE_CHOOSER_FILTER, '', ['root' => $geruest['refs']['g']]) === '', 'nothing for someone who may not shape the model');

exit($fehler === 0 ? 0 : 1);
