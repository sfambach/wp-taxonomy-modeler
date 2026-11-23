<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\WordPress\Rights;

/**
 * The full-screen frame of every taxmod page (D-1585, D-1409): the WordPress bars go, a small taxmod bar offers start,
 * own settings, profile, leaving full screen and logout. One switch per person; a taxmod user is always in it.
 *
 * ```mermaid
 * flowchart LR
 *   M["admin_menu: pages under «taxmod»"] --> P{"taxmod page?"}
 *   P -->|yes, switch on or taxmod user| S["style: no WordPress bars"]
 *   S --> B["taxmod bar"]
 * ```
 *
 * @see docs/pakete/rechte/stufe-2.md
 */
final class FullScreen
{
    /** admin-post: switch full screen on or off for oneself. */
    public const ACTION = 'taxmod_fullscreen';

    private const META = 'taxmod_fullscreen';

    /** Where the stock module kept the same switch before (D-1232); read once as the starting value. */
    private const OLD_META = 'taxmod_stock_fullscreen';

    /** `apply_filters(SETTINGS_URL_FILTER, '')`: the page of a person's own settings, from the module that draws them. */
    public const SETTINGS_URL_FILTER = 'taxmod_own_settings_url';

    /** @var array<string, string> page slug => capability of every page under the taxmod menu */
    private static array $pages = [];

    public static function isOn(int $user = 0): bool
    {
        $user = $user ?: get_current_user_id();

        if ($user > 0 && user_can($user, Rights::APP_ONLY)) {
            return true;
        }

        $on = (string) get_user_meta($user, self::META, true);

        return $on === '' ? get_user_meta($user, self::OLD_META, true) === '1' : $on === '1';
    }

    public static function set(bool $on): void
    {
        update_user_meta(get_current_user_id(), self::META, $on ? '1' : '0');
    }

    /** admin-post: capability → nonce → act → back to the page. A taxmod user cannot leave full screen. */
    public static function toggle(): void
    {
        if (! current_user_can(Rights::VIEW) || current_user_can(Rights::APP_ONLY)) {
            ListPage::fail(__('You are not allowed to do this.', 'taxmod'));
        }

        ListPage::nonce(self::ACTION);
        self::set(! self::isOn());
        wp_safe_redirect(wp_validate_redirect(esc_url_raw(wp_unslash((string) ($_POST['return'] ?? ''))), admin_url()));
        exit;
    }

    /** admin_menu, last: every page a module hung under the taxmod menu, kept before a module empties the menu at admin_head. */
    public static function capture(): void
    {
        global $submenu;

        self::$pages = [];

        foreach ((array) ($submenu['taxmod'] ?? []) as $entry) {
            if (is_array($entry) && isset($entry[1], $entry[2])) {
                self::$pages[(string) $entry[2]] = (string) $entry[1];
            }
        }
    }

    /** @return array<string, string> page slug => capability */
    public static function pages(): array
    {
        return self::$pages;
    }

    public static function onTaxmodPage(): bool
    {
        global $pagenow;

        return $pagenow === 'admin.php' && isset(self::$pages[sanitize_key(wp_unslash((string) ($_GET['page'] ?? '')))]);
    }

    /** admin_head: the frame, on taxmod pages — and on the profile of a taxmod user, who has no other screen. */
    public static function style(): string
    {
        global $pagenow;

        $frame = self::onTaxmodPage() || ($pagenow === 'profile.php' && current_user_can(Rights::APP_ONLY));

        if (! $frame || ! self::isOn()) {
            return '';
        }

        return '<style>#wpadminbar,#adminmenumain,#adminmenuback,#adminmenuwrap,#wpfooter,#screen-meta-links{display:none}html.wp-toolbar{padding-top:0}#wpcontent,#wpfooter{margin-left:0}#wpcontent{padding-left:20px}'
            . '.taxmod-fullscreen-bar{position:fixed;right:12px;bottom:12px;z-index:9990;display:flex;gap:12px;align-items:center;padding:6px 12px;background:#fff;border:1px solid #c3c4c7;box-shadow:0 1px 3px rgba(0,0,0,.1)}'
            . '.taxmod-fullscreen-bar form{margin:0}</style>';
    }

    /** in_admin_header: the taxmod bar in full screen — the only way out of it besides the switch. */
    public static function bar(): string
    {
        if (self::style() === '') {
            return '';
        }

        $back  = esc_url_raw((is_ssl() ? 'https://' : 'http://') . sanitize_text_field(wp_unslash((string) ($_SERVER['HTTP_HOST'] ?? ''))) . wp_unslash((string) ($_SERVER['REQUEST_URI'] ?? '')));
        $own   = (string) apply_filters(self::SETTINGS_URL_FILTER, '');
        $links = [
            [__('Start', 'taxmod'), TaxmodUser::start()],
            ...($own === '' ? [] : [[__('My settings', 'taxmod'), $own]]),
            [__('Profile', 'taxmod'), admin_url('profile.php')],
        ];
        $html = '<nav class="taxmod-fullscreen-bar" aria-label="' . esc_attr__('Taxmod', 'taxmod') . '">';

        foreach ($links as [$label, $url]) {
            $html .= '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }

        if (! current_user_can(Rights::APP_ONLY)) {
            $html .= self::switchForm($back);
        }

        return $html . '<a href="' . esc_url(wp_logout_url()) . '">' . esc_html__('Log out', 'taxmod') . '</a></nav>';
    }

    /** The switch as a button, for the bar and for a module's own navigation (the stock's shell). */
    public static function switchForm(string $back): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . wp_nonce_field(self::ACTION, '_wpnonce', true, false) . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . '<input type="hidden" name="return" value="' . esc_attr($back) . '"><button type="submit" class="button-link">'
            . (self::isOn() ? esc_html__('With WordPress bars', 'taxmod') : esc_html__('Full screen', 'taxmod')) . '</button></form>';
    }
}
