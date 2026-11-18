<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

/**
 * The one-time mark of a form (D-1434): the first act that carries it uses it up, a second request with the same mark acts no more
 * and goes where the first went.
 *
 * ```mermaid
 * flowchart LR
 *   R["request with mark"] --> C{"claim"}
 *   C -->|new| W["act runs"] --> S["settle: where it went"]
 *   C -->|known| E["no act: the first one's way"]
 *   W -->|failed| F["release: a retry may act"]
 * ```
 *
 * ⚠️ *His word 2026-11-18 07:53: «wenn man dann zweimal Buttons drückt, dass die Aktion zum Beispiel zweimal Platz anlegt».* The page
 * script puts the mark in each form it sends (`assets/live.js`); a request without one acts as before. Checked once for every admin-post
 * request ({@see self::guard()}), not per handler: the node page has its own. The claim is one `INSERT IGNORE`
 * on the option name, so two requests at the same moment cannot both win. Kept two days, longer than a nonce lives.
 *
 * @see docs/NewConcept/90-decision-log.md D-1434
 */
final class OnceMark
{
    public const FIELD = '_taxmod_once';

    private const PREFIX = 'taxmod_once_';

    private const RUNNING = '';

    /** How long a second request waits for the first one still running, in tenths of a second. */
    private const WAIT_TENTHS = 100;

    private const KEEP_SECONDS = 2 * DAY_IN_SECONDS;

    /** The mark the request carries; null: none, or not one of ours. */
    public static function ofRequest(): ?string
    {
        $mark = sanitize_text_field(wp_unslash((string) ($_POST[self::FIELD] ?? '')));

        return preg_match('/^[A-Za-z0-9-]{8,64}$/', $mark) === 1 ? $mark : null;
    }

    /** The mark this request used up and has not yet settled. */
    private static ?string $current = null;

    /**
     * For every admin-post request (`admin_init`): a mark used before ends the request where the first one went; a new one is
     * claimed and settled where this request goes. One place for every act — the node page, the modules, `ListPage::act` alike.
     */
    public static function guard(): void
    {
        if (($GLOBALS['pagenow'] ?? '') !== 'admin-post.php') {
            return;
        }

        $earlier = self::enter();

        if ($earlier !== null) {
            wp_safe_redirect($earlier !== '' ? $earlier : (wp_get_referer() ?: admin_url()));
            exit;
        }

        if (self::$current !== null) {
            add_filter('wp_redirect', [self::class, 'leave'], PHP_INT_MAX);
            register_shutdown_function([self::class, 'atEnd']);
        }
    }

    /** Null: this request may act (with or without a mark). A string: the mark was used — where the first act went (empty: back). */
    public static function enter(): ?string
    {
        $mark = self::ofRequest();

        if ($mark === null) {
            return null;
        }

        $earlier = self::claim($mark);

        if ($earlier === null) {
            self::$current = $mark;
        }

        return $earlier;
    }

    /** The act goes somewhere: a second request goes there too. As a `wp_redirect` filter it hands the address on unchanged. */
    public static function leave(string $location): string
    {
        if (self::$current !== null) {
            self::settle(self::$current, $location);
            self::$current = null;
        }

        return $location;
    }

    /** The act failed (`ListPage::fail`): nothing happened, so the same form may try again. */
    public static function giveBack(): void
    {
        if (self::$current !== null) {
            self::release(self::$current);
            self::$current = null;
        }
    }

    /** A request that ended without going anywhere: a crash or a refusal gives the mark back, a download or a page counts as done. */
    public static function atEnd(): void
    {
        $error = error_get_last();

        // *A refusal (`wp_die`, a stale nonce) answers with an error status: nothing happened either.*
        if (($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) || http_response_code() >= 400) {
            self::giveBack();

            return;
        }

        self::leave('');
    }

    /**
     * Use the mark up. Null: it was new, the act may run. A string: it was used before — where the first act went (empty: back to
     * its page), after waiting for a first act still running.
     */
    private static function claim(string $mark): ?string
    {
        global $wpdb;

        self::prune();

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            self::name($mark),
            self::value(self::RUNNING)
        ));

        if ($inserted === 1) {
            return null;
        }

        for ($i = 0; $i < self::WAIT_TENTHS; $i++) {
            $went = self::wentTo($mark);

            if ($went !== null && $went !== self::RUNNING) {
                return $went === 'back' ? '' : $went;
            }

            usleep(100_000);
        }

        return '';
    }

    /** The act ran: a second request goes where this one went. */
    private static function settle(string $mark, string $to): void
    {
        global $wpdb;

        $wpdb->update($wpdb->options, ['option_value' => self::value($to === '' ? 'back' : $to)], ['option_name' => self::name($mark)]);
    }

    private static function release(string $mark): void
    {
        global $wpdb;

        $wpdb->delete($wpdb->options, ['option_name' => self::name($mark)]);
    }

    /** Null: unknown; empty: still running; else where it went ('back': its page). */
    private static function wentTo(string $mark): ?string
    {
        global $wpdb;

        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::name($mark)));

        return is_string($value) ? (string) substr($value, 11) : null;
    }

    /** A mark belongs to the person who sent it. */
    private static function name(string $mark): string
    {
        return self::PREFIX . md5(get_current_user_id() . '|' . $mark);
    }

    /** Ten digits of time, a bar, then where the act went — so old marks can be dropped by comparing the text. */
    private static function value(string $to): string
    {
        return sprintf('%010d|%s', time(), $to);
    }

    /** One request in twenty drops the marks older than two days (no query per act). */
    private static function prune(): void
    {
        global $wpdb;

        if (wp_rand(1, 20) !== 1) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %s",
            $wpdb->esc_like(self::PREFIX) . '%',
            sprintf('%010d|', time() - self::KEEP_SECONDS)
        ));
    }
}
