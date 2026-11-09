<?php declare(strict_types=1);
/**
 * A failing act never ends on an empty page: the message goes to the message field of the page it came from (D-1254).
 *
 *     php scripts/dev/act-failure-check.php [path/to/wordpress]
 *
 * @see docs/gestaltung-und-architektur.md V-rules
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

use Taxmod\WordPress\Admin\ListPage;

// Held back so the acts below can still send their headers, as in a real request.
ob_start();
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $bad;
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . ($passed || $detail === '' ? '' : " — $detail") . "\n";
    $bad += $passed ? 0 : 1;
}

final class Redirected extends \RuntimeException
{
}

// A redirect is caught before its `exit`, so the run can look at where it went.
add_filter('wp_redirect', static function (string $to): never {
    throw new Redirected($to);
}, 1);
add_filter('allowed_redirect_hosts', static fn (array $hosts): array => [...$hosts, 'example.test']);

$admin = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admin[0] ?? 1));

$back = admin_url('admin.php?page=taxmod-lager');
$went = static function (callable $act): string {
    try {
        $act();
    } catch (Redirected $r) {
        return $r->getMessage();
    }

    return '';
};

$to = $went(static fn () => ListPage::act(static function (): string {
    throw new \RuntimeException('Versand an eBay abgelehnt: 401');
}, $back));
check('a failing act goes back to the page it came from', $to === $back, $to);

$page = ListPage::messages();
check('that page shows the message in the message field', str_contains($page, 'notice-error') && str_contains($page, 'Versand an eBay abgelehnt: 401'), $page);
check('the message shows once', ! str_contains(ListPage::messages(), 'notice-error'));

$to = $went(static fn () => ListPage::act(static fn (): string => admin_url('admin.php?page=done'), $back));
check('a successful act goes where it says', str_ends_with($to, 'page=done'), $to);
check('a successful act leaves no message', ! str_contains(ListPage::messages(), 'notice-error'));

$to = $went(static fn () => ListPage::fail('', $back));
check('an empty message still says something', $to === $back && str_contains(ListPage::messages(), 'could not be completed'));

$_SERVER['HTTP_REFERER'] = admin_url('admin.php?page=from-here');
$to = $went(static fn () => ListPage::fail('x'));
check('without an address it goes back to the referring page', str_ends_with($to, 'page=from-here'), $to);
ListPage::messages();

echo $bad === 0 ? "\nall green\n" : "\n$bad failed\n";
ob_end_flush();
exit($bad === 0 ? 0 : 1);
