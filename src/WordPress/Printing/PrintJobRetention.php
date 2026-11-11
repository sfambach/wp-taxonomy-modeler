<?php declare(strict_types=1);

namespace Taxmod\WordPress\Printing;

/**
 * How long finished print jobs stay (D-1307): the days come from the Configuration › Printing, once a day WP-Cron removes the
 * older ones in one statement.
 *
 * @see docs/NewConcept/90-decision-log.md D-1307
 */
final class PrintJobRetention
{
    public const OPTION = 'taxmod_print_jobs_keep_days';

    /** His word 2026-11-11: «so nach 7 Tagen». */
    public const DEFAULT_DAYS = 7;

    public const MAX_DAYS = 3650;

    public const SCHEDULE_HOOK = 'taxmod_print_jobs_purge';

    public static function boot(string $file): void
    {
        register_deactivation_hook($file, static fn () => wp_clear_scheduled_hook(self::SCHEDULE_HOOK));
        add_action('admin_init', static function (): void {
            if (! wp_next_scheduled(self::SCHEDULE_HOOK)) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::SCHEDULE_HOOK);
            }
        });
        add_action(self::SCHEDULE_HOOK, self::run(...));
    }

    /** 0 keeps them for ever. */
    public static function days(): int
    {
        return self::clamp((int) get_option(self::OPTION, self::DEFAULT_DAYS));
    }

    public static function save(int $days): void
    {
        update_option(self::OPTION, self::clamp($days), false);
    }

    /** @return int how many jobs were removed */
    public static function run(): int
    {
        PrintJobSchema::upgrade();

        return (new PrintJobs())->purge(self::days());
    }

    private static function clamp(int $days): int
    {
        return max(0, min(self::MAX_DAYS, $days));
    }
}
