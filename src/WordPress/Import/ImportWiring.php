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
            AutoTake::run();
        });

        // Priority 1: the shared «Import» tab stands before the tabs of the modules that feed it.
        add_filter(SettingsScreen::TABS_FILTER, ImportTab::register(...), 1);
        add_action('admin_menu', static function (): void {
            add_submenu_page('taxmod', __('Inbox', 'taxmod'), __('Inbox', 'taxmod'), Plugin::CAPABILITY, InboxScreen::PAGE, static function (): void {
                // debug.log is off on the live site; a failure shows its text here instead of a white page.
                try {
                    print (new InboxScreen())->render();
                } catch (\Throwable $failure) {
                    print '<div class="wrap"><div class="notice notice-error"><p>' . esc_html(sprintf('%s: %s (%s:%d)', $failure::class,
                        $failure->getMessage(), basename($failure->getFile()), $failure->getLine())) . '</p></div></div>';
                }
            });
        }, 11);

        add_action('admin_post_' . InboxScreen::TAKE, static fn () => (new InboxScreen())->handleTake());
        add_action('admin_post_' . InboxScreen::DISCARD, static fn () => (new InboxScreen())->handleDiscard());
        add_action('admin_post_' . InboxScreen::DISCARD_SELECTED, static fn () => (new InboxScreen())->handleDiscardSelected());
        add_action('admin_post_' . InboxScreen::FETCH, static fn () => (new InboxScreen())->handleFetch());
        add_action('admin_post_' . InboxScreen::FETCH_ALL, static fn () => (new InboxScreen())->handleFetchAll());
        add_action('admin_post_' . InboxScreen::SEARCH, static fn () => (new InboxScreen())->handleSearch());
        add_action('admin_post_' . InboxScreen::BACKLOG, static fn () => (new InboxScreen())->handleBacklog());
        add_action('admin_post_' . InboxScreen::RESTART, static fn () => (new InboxScreen())->handleRestart());
    }
}
