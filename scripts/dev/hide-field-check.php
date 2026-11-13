<?php declare(strict_types=1);
/**
 * An agent hides a field like the eye on the screen (D-1348, D-1144): inherited — at that node and below only; own — everywhere.
 *
 *     WP_ROOT=/path/to/wordpress php scripts/dev/hide-field-check.php
 *
 * @see docs/NewConcept/90-decision-log.md D-1144
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
use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
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
$text  = (new WpdbNodeRepository())->byImplementations([TextType::class])[TextType::class] ?? null;
$apply = wp_get_ability('taxmod/apply');

$built = $apply->execute(['changes' => [
    ['op' => 'create_node', 'ref' => 'a', 'name' => '__hf Kondensatoren', 'parent' => $model->id],
    ['op' => 'create_node', 'ref' => 'b', 'name' => '__hf Keramik', 'parent' => '@a'],
    ['op' => 'create_node', 'ref' => 'c', 'name' => '__hf Folie', 'parent' => '@a'],
    ['op' => 'add_field', 'ref' => 'f', 'node' => '@a', 'target' => $text?->id ?? 0, 'name' => '__hf Dielektrikum'],
]]);

check('the test nodes are built', is_array($built), is_wp_error($built) ? $built->get_error_message() : '');

if (! is_array($built)) {
    exit(1);
}

$hide = $apply->execute(['changes' => [['op' => 'hide_field', 'node' => $built['refs']['b'], 'field' => $built['refs']['f'], 'hidden' => true]]]);

if (is_wp_error($hide) && str_contains($hide->get_error_message(), 'no «hidden» setting')) {
    // This database lacks the root setting of D-1144 (einstellungen-check reports it too): the op must say so, not pass silently.
    check('without the root setting the op refuses loudly', true);
    echo "\n  SKIP the rest: no «hidden» setting at the root of this database\n";
    echo $bad === 0 ? "\nall green\n" : "\n$bad failed\n";
    exit($bad === 0 ? 0 : 1);
}

check('hide_field is applied', is_array($hide), is_wp_error($hide) ? $hide->get_error_message() : '');

['a' => $a, 'b' => $b, 'c' => $c, 'f' => $f] = $built['refs'];
$editor   = $plugin->editor();
$hiddenAt = static function (int $node) use ($editor, $f): ?bool {
    foreach ($editor->fieldsOf($node) as $row) {
        if ($row->id === $f) {
            return $row->hide;
        }
    }

    return null;
};

check('the inherited field is hidden at the subgroup', $hiddenAt($b) === true, var_export($hiddenAt($b), true));
check('the sibling and the owner still show it', $hiddenAt($c) === false && $hiddenAt($a) === false);

$refused = $apply->execute(['changes' => [['op' => 'hide_field', 'node' => $a, 'field' => 999999999, 'hidden' => true]]]);
check('a field the node lacks is refused', is_wp_error($refused));

$apply->execute(['changes' => [['op' => 'hide_field', 'node' => $b, 'field' => $f, 'hidden' => false]]]);
check('hidden false shows it again', $hiddenAt($b) === false);

$apply->execute(['changes' => [['op' => 'hide_field', 'node' => $a, 'field' => $f, 'hidden' => true]]]);
check('at the owner the field is hidden everywhere', $hiddenAt($a) === true && $hiddenAt($c) === true);

echo $bad === 0 ? "\nall green\n" : "\n$bad failed\n";
exit($bad === 0 ? 0 : 1);
