<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\WordPress\Rights;

/**
 * The taxmod user (D-1585): after login it lands on the start page of its role, always in full screen, never in the rest
 * of wp-admin and without the WordPress toolbar. The administrator sets it per person with a tick on the profile.
 *
 * ```mermaid
 * flowchart LR
 *   L["login"] --> A["wp-admin"]
 *   A --> D{"taxmod page, profile or a request of a page?"}
 *   D -->|no| S["start(): first start page of a right it holds and may open"]
 *   D -->|yes| F["page in full screen"]
 * ```
 *
 * @see docs/pakete/rechte/stufe-2.md
 */
final class TaxmodUser
{
    /**
     * `apply_filters(START_PAGES_FILTER, [])`: list of `['right' => …, 'page' => slug, 'args' => [...]]`, in the order a module
     * prefers them; the first whose right the person holds and whose page it may open is its start page.
     */
    public const START_PAGES_FILTER = 'taxmod_start_pages';

    /** The tick on the profile. */
    private const FIELD = 'taxmod_app_only';

    /** What a taxmod user may open besides the taxmod pages: its profile and the requests the pages send. */
    private const ALLOWED = ['profile.php', 'admin-post.php', 'admin-ajax.php', 'async-upload.php'];

    /**
     * login_redirect: a taxmod user goes to wp-admin, where {@see self::guard()} sends it on to its start page. `index.php` and not
     * `admin_url()`: WordPress sends a person without `edit_posts` from the bare admin address to its profile.
     */
    public static function loginRedirect(string $to, string $requested, mixed $user): string
    {
        return $user instanceof \WP_User && $user->has_cap(Rights::APP_ONLY) ? admin_url('index.php') : $to;
    }

    public static function showAdminBar(bool $show): bool
    {
        return current_user_can(Rights::APP_ONLY) ? false : $show;
    }

    /** admin_menu, after {@see FullScreen::capture()}: any other wp-admin page sends a taxmod user to its start page. */
    public static function guard(): void
    {
        global $pagenow;

        if (! current_user_can(Rights::APP_ONLY) || wp_doing_ajax()) {
            return;
        }

        $to = self::destination((string) $pagenow, sanitize_key(wp_unslash((string) ($_GET['page'] ?? ''))));

        if ($to !== null) {
            wp_safe_redirect($to);
            exit;
        }
    }

    /** Where a taxmod user is sent from this page, or null when it may stay. */
    public static function destination(string $pagenow, string $page): ?string
    {
        if (in_array($pagenow, self::ALLOWED, true) || ($pagenow === 'admin.php' && isset(FullScreen::pages()[$page]))) {
            return null;
        }

        return self::start();
    }

    /** The start page of the current person: the first start page it holds the right for and may open, else its first taxmod page. */
    public static function start(): string
    {
        $pages   = FullScreen::pages();
        $mayOpen = static fn (string $page): bool => isset($pages[$page]) && current_user_can($pages[$page]);
        $wanted  = apply_filters(self::START_PAGES_FILTER, []);

        foreach (is_array($wanted) ? $wanted : [] as $entry) {
            $page = (string) ($entry['page'] ?? '');

            if (is_array($entry) && current_user_can((string) ($entry['right'] ?? '')) && $mayOpen($page)) {
                return add_query_arg(['page' => $page, ...(array) ($entry['args'] ?? [])], admin_url('admin.php'));
            }
        }

        foreach (array_keys($pages) as $page) {
            if ($mayOpen((string) $page)) {
                return add_query_arg('page', (string) $page, admin_url('admin.php'));
            }
        }

        return admin_url('profile.php');
    }

    /** edit_user_profile: the tick, for whoever may change this person's roles; never on an administrator. */
    public static function field(\WP_User $user): string
    {
        if (! current_user_can('promote_user', $user->ID) || Rights::isAdministrator($user)) {
            return '';
        }

        return '<h2>' . esc_html__('Taxonomy Modeller', 'taxmod') . '</h2><table class="form-table" role="presentation"><tbody><tr><th scope="row">'
            . esc_html__('Only taxmod, in full screen', 'taxmod') . '</th><td><label>'
            . ListPage::field(\Taxmod\Core\Description\InputKind::Tick, self::FIELD, '1', ['checked' => $user->has_cap(Rights::APP_ONLY)])
            . ' ' . esc_html__('After login this person lands in taxmod and sees no other WordPress page', 'taxmod') . '</label></td></tr></tbody></table>';
    }

    /** edit_user_profile_update: capability → nonce (WordPress's own of the profile form) → act. */
    public static function save(int $userId): void
    {
        if (! current_user_can('promote_user', $userId)) {
            return;
        }

        check_admin_referer('update-user_' . $userId);
        self::setFor($userId, ! empty($_POST[self::FIELD]));
    }

    /** Give or take «only taxmod»; an administrator never gets it, so nobody locks the site out of WordPress. */
    public static function setFor(int $userId, bool $on): bool
    {
        $user = get_user_by('id', $userId);

        if (! $user instanceof \WP_User || ($on && Rights::isAdministrator($user))) {
            return false;
        }

        $on ? $user->add_cap(Rights::APP_ONLY) : $user->remove_cap(Rights::APP_ONLY);

        return true;
    }
}
