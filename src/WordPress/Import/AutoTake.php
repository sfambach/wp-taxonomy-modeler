<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Import\Intake;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Plugin;

/**
 * Takes what needs no choice without the review page, once he switches it on (D-962): a new order from the source that
 * is responsible for it, and a status update of an order that is already there. Everything else stays in the inbox — a
 * status waits there until its order exists. Each module has its own flag, on unless he switches it off (D-1066: «ok wir
 * übernehmen bestellungen automatisch ein flag in den einstellungen der api module default gesetzt»).
 *
 * ⚠️ *The schedule has no logged-in user, and the model is written only through the abilities as a user (D-936). So the
 * switch remembers **who** turned it on and the run acts as that user, as long as that user may still shape the model.*
 *
 * @see docs/pakete/import/konzept.md §3.7
 */
final class AutoTake
{
    public const OPTION = 'taxmod_import_auto_take';
    /** Per module tab: false where he switched automatic taking off; on unless he did (D-1066). */
    public const MODULES_OPTION = 'taxmod_import_auto_take_modules';
    public const MODULE_FIELD = 'taxmod_auto_take_module';

    public static function moduleOn(string $tab): bool
    {
        return (bool) (((array) get_option(self::MODULES_OPTION, []))[$tab] ?? true);
    }

    /** Saving a module tab with the flag on also remembers who the runs act as, when nobody does yet. */
    public static function switchModule(string $tab, bool $on): void
    {
        $modules = (array) get_option(self::MODULES_OPTION, []);
        $modules[$tab] = $on;
        update_option(self::MODULES_OPTION, $modules, false);

        if ($on && self::user() === 0) {
            self::switchTo(true);
        }
    }

    /** The flag in the part «Settings» of every module tab. */
    public static function moduleField(string $tab): string
    {
        return '<p><label><input type="hidden" name="' . esc_attr(self::MODULE_FIELD) . '" value="0">'
            . '<input type="checkbox" name="' . esc_attr(self::MODULE_FIELD) . '" value="1"' . checked(self::moduleOn($tab), true, false) . '> '
            . esc_html__('Take new orders and status updates of this module automatically, without review.', 'taxmod')
            . '</label></p>';
    }

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

            $model = new ModelGateway(Targets::load());
            $intake = new Intake(Duties::load(), array_keys(Sources::all()));

            $tabs = SettingsScreen::moduleTabs();

            foreach ($table->openGroups() as $rows) {
                $module = InboxScreen::moduleOf($rows, $tabs);

                if (($module === null || self::moduleOn($module)) && InboxScreen::takeable($rows) && $screen->take($rows, null) === true) {
                    $taken++;

                    continue;
                }

                // Nothing new for an order the model has (D-994): closed, not counted as taken.
                $screen->settle($rows, $model, $intake);
            }

            return $taken;
        } finally {
            if ($before === 0) {
                wp_set_current_user(0);
            }
        }
    }
}
