<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\Core\Service\PageTimes;
use Taxmod\WordPress\Persistence\Query;

/**
 * Writes down what every Taxmod page took, after the page is sent — for the modeller's own pages and every module's, at one place.
 *
 * His word 2026-11-19: «dass du das mal mitloggst, vielleicht für jede Seite. Dann kann ich dir das schicken, dann musst du
 * nicht messen.» The line of {@see DrawingTimes} stands only under the model page and only in the developer mode; this is
 * kept for every page and read without him (`taxmod/page-times`). Always on; the switch in the developer mode turns it off.
 *
 * ```mermaid
 * flowchart LR
 *   P["admin request: page or action taxmod…"] -->|shutdown| R["record(): ms, statements, memory"]
 *   R --> O["option: ring of the last calls"]
 *   O --> A["ability taxmod/page-times"]
 * ```
 *
 * ⚠️ *Only the page, its tab and the action are kept — no search word, no record id, no user: a log is no place for what
 * somebody looked for.*
 *
 * @see docs/pakete/ladezeit/konzept.md
 */
final class PageTimesLog
{
    /** The ring, not autoloaded: only the request that writes or reads it loads it. */
    public const OPTION = 'taxmod_page_times';

    /** The switch: on unless somebody turned it off. Its row stands with the developer details. */
    public const SWITCH = 'taxmod_dev_page_times';

    /** @var array{b: int, r: int} milliseconds since the request began, by station: `b` WordPress and the plugins are loaded, `r` the page is drawn. */
    private static array $at = ['b' => 0, 'r' => 0];

    /** What this request waited for other servers: milliseconds in all, and when the call now running began. */
    private static float $outside = 0.0;

    private static float $calling = 0.0;

    public static function register(): void
    {
        add_action('shutdown', [self::class, 'record']);

        // D-1478: where the time went. A call of 58 s with 139 statements and 12 MB said that it was slow and not why.
        add_action('admin_init', static function (): void { self::$at['b'] = self::sinceStart(); }, PHP_INT_MIN);
        add_action('in_admin_footer', static function (): void { self::$at['r'] = self::sinceStart(); }, PHP_INT_MAX);
        add_filter('pre_http_request', static function (mixed $answer): mixed {
            self::$calling = microtime(true);

            return $answer;
        }, PHP_INT_MIN);
        add_action('http_api_debug', static function (): void {
            if (self::$calling > 0.0) {
                self::$outside += (microtime(true) - self::$calling) * 1000;
                self::$calling  = 0.0;
            }
        });
    }

    private static function sinceStart(): int
    {
        return isset($_SERVER['REQUEST_TIME_FLOAT']) ? (int) round((microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000) : 0;
    }

    /** One entry for this request if it drew a Taxmod page or answered a Taxmod action; nothing otherwise. */
    public static function record(): void
    {
        // A guard run promises to leave nothing behind and counts what a GET writes: the log stays out of both.
        if (defined('TAXMOD_NO_WRITE') || get_option(self::SWITCH, '1') === '0') {
            return;
        }

        $page = self::subject(is_admin(), wp_unslash($_GET), wp_unslash($_REQUEST));

        if ($page !== null) {
            self::write($page);
        }
    }

    /** Puts one call of `$page` at the end of the ring, with what this request took up to now. */
    public static function write(string $page): void
    {
        $started = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true);
        $before  = get_option(self::OPTION, []);

        update_option(self::OPTION, PageTimes::with(is_array($before) ? array_values($before) : [], [
            't'  => time(),
            'p'  => $page,
            'ms' => (int) round((microtime(true) - $started) * 1000),
            'q'  => Query::asked(),
            'mb' => (int) round(memory_get_peak_usage(true) / 1048576),
            'o'  => self::opcache() ? 1 : 0,
            'b'  => self::$at['b'],
            'r'  => self::$at['r'],
            'h'  => (int) round(self::$outside),
        ]), false);
    }

    /** @return list<array<string, int|string>> oldest first */
    public static function entries(): array
    {
        $entries = get_option(self::OPTION, []);

        return is_array($entries) ? array_values($entries) : [];
    }

    public static function opcache(): bool
    {
        return function_exists('opcache_get_status') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The summary per page as a table, slowest first — under the settings, while the developer mode shows the drawing times.
     */
    public static function table(): string
    {
        if (! SettingsScreen::developerShows(DrawingTimes::OPTION)) {
            return '';
        }

        $rows = '';

        foreach (PageTimes::summary(self::entries()) as $page => $took) {
            $rows .= '<tr><td><code>' . esc_html((string) $page) . '</code></td>'
                . '<td>' . (int) $took['calls'] . '</td>'
                . '<td>' . (int) $took['median_ms'] . '</td>'
                . '<td>' . (int) $took['max_ms'] . '</td>'
                . '<td>' . (int) $took['median_queries'] . '</td>'
                . '<td>' . esc_html(wp_date('Y-m-d H:i', (int) $took['last']) ?: '') . '</td></tr>';
        }

        $head = '';

        foreach ([__('Page', 'taxmod'), __('Calls', 'taxmod'), __('Middle ms', 'taxmod'), __('Longest ms', 'taxmod'), __('Middle queries', 'taxmod'), __('Last call', 'taxmod')] as $column) {
            $head .= '<th>' . esc_html($column) . '</th>';
        }

        return '<h2>' . esc_html__('Page times', 'taxmod') . '</h2>'
            . '<p class="description">' . esc_html(sprintf(
                /* translators: 1: how many calls are kept, 2: on or off */
                __('What the last %1$d calls of Taxmod pages took on the server, slowest page first. OPcache: %2$s.', 'taxmod'),
                count(self::entries()),
                self::opcache() ? __('on', 'taxmod') : __('off', 'taxmod')
            )) . '</p>'
            . ($rows === ''
                ? '<p>' . esc_html__('Nothing measured yet.', 'taxmod') . '</p>'
                : '<table class="wp-list-table widefat striped taxmod-page-times"><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table>');
    }

    /**
     * `page` or `page/tab` of an admin page, `action:…` of a fragment or a form — only where the name begins with taxmod.
     *
     * @param array<array-key, mixed> $get     `mixed`: what a request carries is not typed
     * @param array<array-key, mixed> $request
     */
    public static function subject(bool $admin, array $get, array $request): ?string
    {
        if (! $admin) {
            return null;
        }

        $word = static fn (mixed $value): string => is_string($value) ? sanitize_key($value) : '';
        $page = $word($get['page'] ?? '');

        if (str_starts_with($page, 'taxmod')) {
            $tab = $word($get['tab'] ?? '');

            return $page . ($tab === '' ? '' : '/' . $tab);
        }

        $action = $word($request['action'] ?? '');

        return str_starts_with($action, 'taxmod') ? 'action:' . $action : null;
    }
}
