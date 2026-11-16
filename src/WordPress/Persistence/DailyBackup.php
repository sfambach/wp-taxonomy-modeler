<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Service\DailyBackupRetention;

/**
 * One full copy of the model a night, by WP-Cron, instead of one before every change (D-1407).
 *
 * ⚠️ *A copy before each AI change took 31–39 s on the live site and held the host's PHP processes (D-1406). The owner,
 * 2026-11-16: «man kann ja einmal täglich eine Sicherheitskopie machen … aber doch nicht jedes Mal».* Small on purpose: it is
 * named on every request, {@see Backup} only when the night comes.
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class DailyBackup
{
    public const SCHEDULE_HOOK = 'taxmod_daily_backup';

    public static function boot(string $file): void
    {
        register_deactivation_hook($file, static fn () => wp_clear_scheduled_hook(self::SCHEDULE_HOOK));
        // ⚠️ *Also in the cron process, not only on an admin page: after an update through the updater nobody had opened
        // wp-admin, and the night stayed unplanned (16.11.2026). The hourly import fetch brings a cron run within the hour.*
        add_action('init', static function (): void {
            if ((is_admin() || wp_doing_cron()) && ! wp_next_scheduled(self::SCHEDULE_HOOK)) {
                wp_schedule_event(self::nextNight(), 'daily', self::SCHEDULE_HOOK);
            }
        });
        add_action(self::SCHEDULE_HOOK, self::run(...));
    }

    /** A copy younger than this is the night's copy already; a missed night is caught up once, not twice. */
    private const AT_MOST_EVERY = 20 * HOUR_IN_SECONDS;

    /** An ability's answer warns when the newest copy is older than this. */
    private const WARN_AFTER = 36 * HOUR_IN_SECONDS;

    /**
     * ⚠️ *Only in the cron process, never in a visitor's request (architecture thread, 16.11.2026): on Strato WP-Cron starts
     * with a page view, and a copy takes half a minute.*
     *
     * @return string|null The file of the new copy, null when none was due or this is not the cron process.
     */
    public static function run(): ?string
    {
        $latest = self::latest();

        // An administrator may start it by hand (Easy MCP «run cron event»); a visitor's request never.
        if (! (wp_doing_cron() || current_user_can('manage_options')) || ($latest !== null && time() - strtotime($latest['at']) < self::AT_MOST_EVERY)) {
            return null;
        }

        $backup = new Backup();
        $file   = $backup->safetyCopy(rtrim(DailyBackupRetention::PREFIX, '-'), false);
        $dir    = dirname($file);

        foreach (DailyBackupRetention::toRemove(self::names($dir)) as $old) {
            wp_delete_file($dir . '/' . $old);
        }

        return $file;
    }

    /** @return array{file: string, at: string}|null The newest nightly copy, for the answer of an ability. */
    public static function latest(): ?array
    {
        $uploads = wp_upload_dir();
        $dir     = trailingslashit($uploads['basedir']) . Backup::SAFETY_DIR;
        $newest  = DailyBackupRetention::newest(self::names($dir));

        return $newest === null ? null : ['file' => $dir . '/' . $newest, 'at' => gmdate('c', (int) filemtime($dir . '/' . $newest))];
    }

    /**
     * @param array{file: string, at: string}|null $latest
     */
    public static function warning(?array $latest): ?string
    {
        if ($latest === null) {
            return __('There is no nightly backup yet.', 'taxmod');
        }

        return time() - strtotime($latest['at']) > self::WARN_AFTER
            /* translators: %s: date and time of the newest nightly backup */
            ? sprintf(__('The newest nightly backup is from %s, older than 36 hours. Check WP-Cron.', 'taxmod'), $latest['at'])
            : null;
    }

    /** 03:00 site time, the next one to come. */
    private static function nextNight(): int
    {
        $night = new \DateTimeImmutable('today 03:00', wp_timezone());

        return ($night->getTimestamp() > time() ? $night : $night->modify('+1 day'))->getTimestamp();
    }

    /** @return list<string> */
    private static function names(string $dir): array
    {
        return is_dir($dir) ? array_values(array_diff((array) scandir($dir), ['.', '..'])) : [];
    }
}
