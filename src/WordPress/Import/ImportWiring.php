<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\WordPress\Plugin;

/**
 * Hooks the import inbox into WordPress: its table, the «Inbox» page, the «Import» tab, the hourly schedule that runs
 * each fetching source when its own interval has passed (D-952, D-953).
 *
 * @see docs/pakete/import/konzept.md §3.7
 */
final class ImportWiring
{
    public static function boot(string $file): void
    {
        register_activation_hook($file, InboxSchema::upgrade(...));
        register_deactivation_hook($file, static fn () => wp_clear_scheduled_hook(Inbox::SCHEDULE_HOOK));

        add_action('admin_init', InboxSchema::upgrade(...));
        add_action('admin_init', static function (): void {
            if (! wp_next_scheduled(Inbox::SCHEDULE_HOOK)) {
                wp_schedule_event(time() + 300, 'hourly', Inbox::SCHEDULE_HOOK);
            }
        });
        add_action(Inbox::SCHEDULE_HOOK, static function (): void {
            InboxSchema::upgrade();
            (new Inbox())->runDue();
        });

        add_filter(SettingsScreen::TABS_FILTER, ImportTab::register(...));
        add_action('admin_menu', static function (): void {
            add_submenu_page('taxmod', __('Inbox', 'taxmod'), __('Inbox', 'taxmod'), Plugin::CAPABILITY, InboxScreen::PAGE, static fn () => print (new InboxScreen())->render());
        }, 11);

        add_action('admin_post_' . InboxScreen::TAKE, static fn () => (new InboxScreen())->handleTake());
        add_action('admin_post_' . InboxScreen::DISCARD, static fn () => (new InboxScreen())->handleDiscard());
        add_action('admin_post_' . InboxScreen::FETCH, static fn () => (new InboxScreen())->handleFetch());
    }
}
