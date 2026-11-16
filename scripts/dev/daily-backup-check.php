<?php declare(strict_types=1);
/**
 * One full copy a night, only in the cron process, at most one per 20 hours, and an ability makes none (D-1407).
 *
 *     php scripts/dev/daily-backup-check.php [path/to/wordpress]
 *
 * Writes one nightly copy into uploads/taxmod-backups and removes it again; the model is not touched.
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

use Taxmod\WordPress\Persistence\DailyBackup;

$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $bad;
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . ($passed || $detail === '' ? '' : " — $detail") . "\n";
    $bad += $passed ? 0 : 1;
}

check('the night is scheduled', has_action(DailyBackup::SCHEDULE_HOOK) !== false);
check('outside the cron process nothing is copied', DailyBackup::run() === null);

$before = DailyBackup::latest();
$made   = null;

add_filter('wp_doing_cron', '__return_true');

try {
    $made = $before === null ? DailyBackup::run() : null;

    if ($before === null) {
        check('the cron process makes the copy', $made !== null && is_file($made), (string) $made);
        check('the newest copy is that one', (DailyBackup::latest()['file'] ?? '') === $made);
        check('a fresh copy carries no warning', DailyBackup::warning(DailyBackup::latest()) === null);
    }

    check('a second run the same day copies nothing', DailyBackup::run() === null);
    check('without a copy the ability is warned', DailyBackup::warning(null) !== null);
    check('a copy of two days ago is warned about', DailyBackup::warning(['file' => 'x', 'at' => gmdate('c', time() - 2 * DAY_IN_SECONDS)]) !== null);
} finally {
    remove_filter('wp_doing_cron', '__return_true');

    if ($made !== null && is_file($made)) {
        unlink($made);
    }
}

echo $bad === 0 ? "\nall green\n" : "\n$bad failed\n";
exit($bad === 0 ? 0 : 1);
