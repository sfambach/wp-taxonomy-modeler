<?php declare(strict_types=1);
/**
 * A module asks the door for the first rule that holds and gets the same answer the core rule book gives — one rule set for
 * printing and testing (D-1541), so no module builds its own.
 *
 *     php scripts/dev/rule-book-check.php [path/to/wordpress]
 *
 * ```mermaid
 * flowchart LR
 *   M["module: its rule records"] --> D["ModelDoor::firstRule"]
 *   D --> B["RuleBook: by position, node + filters"]
 *   B --> A["rule id, or null: the module's default"]
 * ```
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: null;

if ($root === null) {
    $dir = getcwd();
    while ($dir !== '' && ! is_readable($dir . '/wp-load.php')) {
        $up  = dirname($dir);
        $dir = $up === $dir ? '' : $up;
    }
    $root = $dir;
}

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php. Pass the WordPress folder as the first argument.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';

require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\WordPress\ModelDoor;

$ok  = 0;
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $ok, $bad;

    if ($passed) {
        $ok++;
        echo "  OK   $what\n";

        return;
    }

    $bad++;
    echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n";
}

$door  = new ModelDoor();
$rules = [
    ['id' => 2, 'position' => 1, 'conditions' => [1 => 7], 'filters' => [['field' => 40, 'value' => 'SMD']]],
    ['id' => 3, 'position' => 2, 'conditions' => [1 => 0]],
];

check('the door has firstRule', method_exists($door, 'firstRule'));
check('node and filter hold → that rule', $door->firstRule($rules, [1 => [70, 7]], [40 => ['SMD']]) === 2);
check('filter fails → the next rule', $door->firstRule($rules, [1 => [70, 7]], [40 => ['THT']]) === 3);
check('the caller may refuse a rule', $door->firstRule($rules, [1 => [70, 7]], [40 => ['SMD']], static fn (int $id): bool => $id !== 2) === 3);
check('no rule → null, the caller\'s default', $door->firstRule([$rules[0]], [1 => [5]]) === null);

echo "\n$ok OK, $bad FAIL\n";
exit($bad === 0 ? 0 : 1);
