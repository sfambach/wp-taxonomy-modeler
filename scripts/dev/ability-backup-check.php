<?php declare(strict_types=1);
/**
 * A safety copy an ability asked for stands for the next change lists while it is young, and is renewed before throwing something away (D-1406).
 *
 *     php scripts/dev/ability-backup-check.php [path/to/wordpress]
 *
 * Writes safety copies into uploads/taxmod-backups, as an ability would; the model itself is not touched.
 *
 * @see docs/NewConcept/90-decision-log.md
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: '';

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Pass the WordPress folder as the first argument or WP_ROOT.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\WordPress\Persistence\Backup;

$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $bad;
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . ($passed || $detail === '' ? '' : " — $detail") . "\n";
    $bad += $passed ? 0 : 1;
}

$before = get_option(Backup::ABILITY_MINUTES, null);
update_option(Backup::ABILITY_MINUTES, 30, false);
$made = [];

try {
    $first  = (new Backup())->forAbility(['set_values']);
    $made[] = $first['file'];
    check('the first change list gets a copy', $first['made'] && is_file($first['file']), json_encode($first));

    $second = (new Backup())->forAbility(['set_values', 'move_part']);
    check('the next one stands on it', ! $second['made'] && $second['file'] === $first['file'] && $second['at'] === $first['at'], json_encode($second));

    $removal = (new Backup())->forAbility(['set_values', 'remove_record']);
    $made[]  = $removal['file'];
    check('removing gets a new copy at once', $removal['made'] && $removal['file'] !== $first['file'], json_encode($removal));

    update_option(Backup::ABILITY_MINUTES, 0, false);
    $every  = (new Backup())->forAbility(['set_values']);
    $made[] = $every['file'];
    check('0 minutes copies every time', $every['made'], json_encode($every));
} finally {
    $before === null ? delete_option(Backup::ABILITY_MINUTES) : update_option(Backup::ABILITY_MINUTES, $before, false);
    array_map(static fn (string $file) => is_file($file) && unlink($file), array_filter($made));
}

echo $bad === 0 ? "\nall green\n" : "\n$bad failed\n";
exit($bad === 0 ? 0 : 1);
