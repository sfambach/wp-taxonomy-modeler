<?php declare(strict_types=1);
/**
 * A module reads the symbol of a node through the door, and translating the name leaves it alone (D-1534).
 *
 *     WP_ROOT=/path/to/wordpress php scripts/dev/node-symbol-check.php
 *
 * @see docs/NewConcept/90-decision-log.md D-1534
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

use Taxmod\Core\Model\Branch;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\ModelDoor;
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
$model   = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin)->rootOf(Branch::Model);
$default = SettingsScreen::neutralLocale();
$other   = $default === 'de_DE' ? 'en_US' : 'de_DE';

$built = wp_get_ability('taxmod/apply')->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'l', 'name' => '__ns Länder', 'parent' => $model->id],
    ['op' => 'create_node', 'ref' => 'de', 'name' => '__ns DE', 'parent' => '@l'],
    ['op' => 'create_node', 'ref' => 'fr', 'name' => '__ns FR', 'parent' => '@l'],
    ['op' => 'set_label', 'node' => '@de', 'role' => 'symbol', 'text' => 'DE'],
    ['op' => 'set_label', 'node' => '@de', 'role' => 'name', 'text' => '__ns Germany'],
    ['op' => 'set_label', 'node' => '@de', 'role' => 'name', 'locale' => $other, 'text' => '__ns Deutschland'],
    ['op' => 'set_label', 'node' => '@de', 'role' => 'symbol', 'locale' => $other, 'text' => 'XX'],
]]);

check('the test nodes are built', is_array($built), is_wp_error($built) ? $built->get_error_message() : '');

if (! is_array($built)) {
    exit(1);
}

$ref     = $built['refs'];
$door    = new ModelDoor();
$symbols = $door->symbolsOf([$ref['de'], $ref['fr'], $ref['l']]);

check('the symbol is read, the default language first', $symbols === [$ref['de'] => 'DE'], wp_json_encode($symbols));
check('a node without a symbol is absent, its name is no fallback', ! isset($symbols[$ref['fr']]));
check('nothing asked, nothing read', $door->symbolsOf([]) === []);

$name = $door->tree($ref['l'], 1)[$ref['de']]['name'] ?? '';
check('the name in the default language replaced the typed name, the symbol stays', $name === '__ns Germany' && $symbols[$ref['de']] === 'DE', $name);

wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'trash_node', 'node' => $ref['l']]]]);

exit($bad === 0 ? 0 : 1);
