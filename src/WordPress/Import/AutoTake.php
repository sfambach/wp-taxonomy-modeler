<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\WordPress\Plugin;

/**
 * Takes what needs no choice without the review page, once he switches it on (D-962): a new order from the source that
 * is responsible for it, and a status update of an order that is already there. Everything else stays in the inbox — a
 * status waits there until its order exists.
 *
 * ⚠️ *The schedule has no logged-in user, and the model is written only through the abilities as a user (D-936). So the
 * switch remembers **who** turned it on and the run acts as that user, as long as that user may still shape the model.*
 *
 * @see docs/pakete/import/konzept.md §3.7
 */
final class AutoTake
{
    public const OPTION = 'taxmod_import_auto_take';

    /** The user the runs act as; 0 while it is off. */
    public static function user(): int
    {
        return (int) get_option(self::OPTION, 0);
    }

    public static function switchTo(bool $on): void
    {
        update_option(self::OPTION, $on ? get_current_user_id() : 0, false);
    }

    /** @return int how many groups were taken */
    public static function run(InboxTable $table = new InboxTable()): int
    {
        $user = self::user();

        if ($user === 0) {
            return 0;
        }

        $before = get_current_user_id();

        if ($before === 0) {
            wp_set_current_user($user);
        }

        try {
            if (! current_user_can(Plugin::CAPABILITY)) {
                return 0;
            }

            $screen = new InboxScreen($table);
            $taken = 0;

            foreach ($table->openGroups() as $rows) {
                if (InboxScreen::takeable($rows) && $screen->take($rows, null) === true) {
                    $taken++;
                }
            }

            return $taken;
        } finally {
            if ($before === 0) {
                wp_set_current_user(0);
            }
        }
    }
}
