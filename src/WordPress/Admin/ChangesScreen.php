<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Plugin;

/**
 * The list of the latest changes, one row per change number, with «Undo» in the bulk bar and on each row (D-1410, step E).
 *
 * ⚠️ *The undo is the op `undo_act` of `taxmod/apply` — the same way, the same guard, the same answer as for an agent. A change
 * written over since is refused with the later change named; the message field says so (D-1254).*
 *
 * @see docs/NewConcept/90-decision-log.md D-1410
 */
final class ChangesScreen
{
    public const PAGE = 'taxmod-changes';

    public const ACTION = 'taxmod_undo_act';

    private const FORM = 'taxmod-changes-bulk';

    private const LIMIT = 200;

    public static function boot(): void
    {
        add_action('admin_menu', static function (): void {
            add_submenu_page('taxmod', __('Changes', 'taxmod'), __('Changes', 'taxmod'), Plugin::CAPABILITY, self::PAGE, static function (): void {
                echo (new self())->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() escapes its parts.
            });
        }, 21);
        add_action('admin_post_' . self::ACTION, static fn () => (new self())->handle());
    }

    public static function url(): string
    {
        return admin_url('admin.php?page=' . self::PAGE);
    }

    public function render(): string
    {
        $rows = '';

        foreach ($this->latest() as $act) {
            $rows .= $this->row($act);
        }

        if ($rows === '') {
            $rows = '<tr><td colspan="7">' . esc_html__('No changes yet.', 'taxmod') . '</td></tr>';
        }

        return ListPage::page(__('Changes', 'taxmod'), [], [['html' => self::notice()
            . '<p class="description">' . esc_html__('Every change made through the modeller, its modules and agents has a number. «Undo» takes it back as a new change, which can itself be undone. A change that another one wrote over since is refused; take the later one back first.', 'taxmod') . '</p>'
            . ListPage::bulkBar(self::FORM, self::ACTION, [self::ACTION => __('Undo', 'taxmod')], sprintf(/* translators: %d: number of changes */ _n('the latest %d change', 'the latest %d changes', self::LIMIT, 'taxmod'), self::LIMIT), '', false)
            . ListPage::table([
                [__('No.', 'taxmod'), '6em'],
                [__('When', 'taxmod'), '11em'],
                [__('Who', 'taxmod'), '10em'],
                [__('What', 'taxmod')],
                [__('Rows', 'taxmod'), '5em'],
                [__('Status', 'taxmod'), '12em'],
            ], $rows)]]);
    }

    /** admin-post: undo the ticked changes, newest first, or one row's button on `act`. */
    public function handle(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            ListPage::fail(__('You cannot undo changes.', 'taxmod'));
        }

        ListPage::nonce(self::ACTION);

        $acts = isset($_REQUEST['act'])
            ? [absint(wp_unslash((string) $_REQUEST['act']))]
            : array_map('absint', (array) wp_unslash($_POST['selected'] ?? []));
        $acts = array_values(array_filter(array_unique($acts)));
        rsort($acts);

        ListPage::act(static function () use ($acts): string {
            $undone = [];

            // *Newest first: an older change is only free once the newer ones on the same rows are taken back.*
            foreach ($acts as $act) {
                $answer = wp_get_ability('taxmod/apply')?->execute(['changes' => [['op' => 'undo_act', 'act' => $act]], 'brief' => true]);

                if (is_wp_error($answer)) {
                    throw new \RuntimeException($answer->get_error_message());
                }

                $undone[] = $act;
            }

            return add_query_arg(['taxmod_undone' => implode(',', $undone)], wp_get_referer() ?: self::url());
        });
    }

    /** @return list<array<string, string|null>> */
    private function latest(): array
    {
        return WpdbChangelog::latestActs(self::LIMIT);
    }

    /** @param array<string, string|null> $act */
    private function row(array $act): string
    {
        $user   = $act['by_user'] === null ? false : get_userdata((int) $act['by_user']);
        $who    = $act['by_user'] === null ? __('the machine', 'taxmod') : ($user === false ? '#' . $act['by_user'] : $user->display_name);
        $status = $act['undone_by'] !== null
            ? sprintf(/* translators: %s: change number */ __('taken back by %s', 'taxmod'), $act['undone_by'])
            : '';
        // *A plain POST form (D-1408 updates the page in place); taken back already, the row offers nothing — its undo is the newer row.*
        $button = $act['undone_by'] !== null ? '' : '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="act" value="' . esc_attr((string) (int) $act['act']) . '">'
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false)
            . '<button type="submit" class="button">' . esc_html__('Undo', 'taxmod') . '</button></form>';

        return ListPage::row(self::FORM, (string) $act['act'], [
            esc_html((string) $act['act']),
            esc_html((string) $act['at']),
            esc_html($who),
            esc_html((string) $act['whats']),
            esc_html((string) $act['n']),
            esc_html($status),
        ], $button, '');
    }

    private static function notice(): string
    {
        $undone = sanitize_text_field(wp_unslash((string) ($_GET['taxmod_undone'] ?? '')));

        if ($undone === '') {
            return '';
        }

        return '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(/* translators: %s: change numbers */ __('Taken back: %s. Each undo is a change of its own and can be undone in turn.', 'taxmod'), $undone)) . '</p></div>';
    }
}
