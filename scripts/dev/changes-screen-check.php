<?php declare(strict_types=1);
/**
 * The page «Changes» lists the latest change numbers and offers «Undo» on each and in the bulk bar (D-1410, step E).
 *
 *     WP_ROOT=/path/to/wordpress php scripts/dev/changes-screen-check.php
 *
 * @see docs/NewConcept/90-decision-log.md D-1410
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: '';

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Pass the WordPress folder as the first argument or WP_ROOT.\n");
    exit(2);
}

define('WP_USE_THEMES', false);

require $root . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
// *Wie im Admin: die Seite zeichnet ihre Knöpfe mit `get_submit_button()` (Grenzlauf 2026-11-17, D-1426 — lief nur, wo etwas anderes template.php schon geladen hatte).*
require_once ABSPATH . 'wp-admin/includes/template.php';

use Taxmod\Core\Model\Branch;
use Taxmod\WordPress\Admin\ChangesScreen;
use Taxmod\WordPress\Plugin;

$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $bad;
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . ($passed || $detail === '' ? '' : " — $detail") . "\n";
    $bad += $passed ? 0 : 1;
}

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 1));

$plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($plugin, 'file'))->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$model = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin)->rootOf(Branch::Model);

$angelegt = wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'create_node', 'name' => '__cs Gruppe', 'parent' => $model->id]]]);
$akt      = is_array($angelegt) ? (int) $angelegt['act'] : 0;
check('a change with a number', $akt > 0);

$seite = (new ChangesScreen())->render();
check('the page lists it', str_contains($seite, '<td>' . $akt . '</td>'));
check('its row offers «Undo» as a POST form for that number', (bool) preg_match('/<form method="post"[^>]*>.*?name="act" value="' . $akt . '".*?Undo<\/button><\/form>/s', $seite));
check('the bulk bar offers «Undo»', str_contains($seite, 'value="' . ChangesScreen::ACTION . '">Undo</option>'));

$undo = wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'undo_act', 'act' => $akt]]]);
$seite = (new ChangesScreen())->render();
$nummer = is_array($undo) ? (int) $undo['act'] : 0;
check('taken back, the row says by which change', str_contains($seite, 'taken back by ' . $nummer));
check('and offers no second undo', ! preg_match('/name="act" value="' . $akt . '"/', $seite));
check('the undo has its own row with «Undo»', (bool) preg_match('/name="act" value="' . $nummer . '"/', $seite));

echo $bad === 0 ? "\nAll checks passed.\n" : "\n{$bad} failed.\n";
exit($bad === 0 ? 0 : 1);
