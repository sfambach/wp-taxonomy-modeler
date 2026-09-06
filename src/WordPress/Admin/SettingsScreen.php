<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Renderer\HintMarkup;
use Taxmod\WordPress\Plugin;

/**
 * The installation's own settings — **the screen three decisions had been waiting for**.
 *
 * The owner: *that would be a setting on the admin page — we should tackle those next, otherwise some
 * information may get lost.* He is right that it was overdue: three things had been deferred to a
 * screen that did not exist, each with its own interim.
 *
 * ```mermaid
 * flowchart LR
 *   I["the installation · one answer for the whole site"] --> S[this screen]
 *   N["a node · one answer per node"] --> C["the settings chain"]
 * ```
 *
 * | What | Where it had been living |
 * |---|---|
 * | **developer mode** | a WordPress option nobody could reach ([D-389](../../../docs/NewConcept/90-decision-log.md)), and before that on the **root node**, where it could differ per branch |
 * | **the neutral locale** | falling back to the site language ([D-387](../../../docs/NewConcept/90-decision-log.md)), because there was nowhere to declare it |
 * | **the tree's scale** | nowhere at all — the owner asked for the icon size and the font *in the settings* and I had no honest place to put them |
 *
 * ⚠️ **This closes [OQ-039](../../../docs/NewConcept/91-open-questions.md)**, open since Package 4:
 * *the installation link is where a posture belongs and it does not appear in the modeller.* It does
 * now, as a screen of its own rather than as a node.
 *
 * ⚠️ **A WordPress option and not a setting on a node, and the reason is not convenience.** A setting
 * resolves along the chain — *installation → model root → ancestors → node → use site* — so anything
 * put there **can differ per node**, and *developer mode, but only under Compositions* is not a thing.
 * **A fact about the installation has to live somewhere that cannot vary** ([D-389](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **`CD-5` in order, every time**: capability → nonce → validate → sanitise → act → escape. *An
 * options screen is exactly where that gets skipped «because only an administrator sees it».*
 *
 * @see docs/NewConcept/20-interaction.md
 */
final class SettingsScreen
{
    public const ACTION = 'taxmod_settings';

    /** Which language stands for *everywhere* — the row stored without a locale. */
    public const NEUTRAL_LOCALE = 'taxmod_neutral_locale';

    /** How big the tree's glyphs are, in pixels. */
    public const ICON_SIZE = 'taxmod_icon_size';

    /** How big the tree's text is, in pixels. */
    public const FONT_SIZE = 'taxmod_font_size';

    /**
     * Sizes the owner may pick, and why it is a list rather than a number field.
     *
     * ⚠️ *He walked the numbers himself — «one pixel bigger», «make 20», «25px», then back to 17 —
     * which is what a **list of tried sizes** is for. R28's rule applies to this like anything else: a
     * control offers real choices, and a free number field offers 4px and 400px too.*
     *
     * @var list<int>
     */
    private const SIZES = [13, 15, 17, 20, 25];

    /**
     * What developer mode is, said once.
     *
     * ⚠️ *[D-248](../../../docs/NewConcept/90-decision-log.md) folded test mode into it — **one mode,
     * not two** — so the same switch lifts deletion protection and shows the diagnostics. Two modes
     * that overlap are two things to explain and two ways to be in a surprising state.*
     */
    public function render(): string
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            return '';
        }

        $html = '<div class="wrap">'
            . '<h1>' . esc_html__('Taxonomy Modeller — installation', 'taxmod') . '</h1>'
            . $this->notice()
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">'
            . wp_nonce_field(self::ACTION, '_taxmod_nonce', true, false)
            . '<table class="form-table" role="presentation">'
            . $this->developerRow()
            . $this->localeRow()
            . $this->sizeRow(self::ICON_SIZE, __('Icon size', 'taxmod'), self::defaultIconSize(), __('The glyphs in the tree and on its buttons.', 'taxmod'))
            . $this->sizeRow(self::FONT_SIZE, __('Text size', 'taxmod'), self::defaultFontSize(), __('The names in the tree. The owner asked for these two together, because a 17px glyph beside 13px text reads as a mistake.', 'taxmod'))
            . '</table>'
            . get_submit_button(__('Save', 'taxmod'))
            . '</form></div>';

        return $html;
    }

    /**
     * The posture, as a switch.
     *
     * ⚠️ **Off by default, because a switch nobody set is off** — and because the diagnostics it shows
     * are for whoever is building, not for whoever is modelling.
     */
    private function developerRow(): string
    {
        return '<tr><th scope="row">' . esc_html__('Developer mode', 'taxmod') . '</th><td>'
            . '<label><input type="checkbox" name="developer" value="1"'
            . checked(self::inDeveloperMode(), true, false) . '> '
            . esc_html__('Show diagnostics and lift the deletion guards', 'taxmod')
            . '</label>'
            // ⚠️ *Hinter das Fragezeichen, nicht unter den Schalter
            // ([D-661](../../../docs/NewConcept/90-decision-log.md)).*
            . HintMarkup::icon(
                __('One mode, not two: the same switch that shows which renderer drew what also lets a protected node be parked.', 'taxmod')
            )
            . '</td></tr>';
    }

    /**
     * Which language is *the* one.
     *
     * ⚠️ **This is what [D-387](../../../docs/NewConcept/90-decision-log.md) was waiting for.** The
     * owner: *neutral is probably too much — we declare `en_US` as neutral in the admin config.* The
     * storage does not change: a label written in this language goes into the row with **no** locale,
     * which is what *valid everywhere* has always meant ([D-317](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *Offered from what WordPress has installed plus the site's own language, because that is a
     * boundary fact and the core could not list them (`CD-1`).*
     */
    private function localeRow(): string
    {
        $offered = [get_locale() => get_locale()];

        foreach (get_available_languages() as $one) {
            $offered[$one] = $one;
        }

        ksort($offered);

        $current = self::neutralLocale();
        $options = '';

        foreach ($offered as $one) {
            $options .= '<option value="' . esc_attr($one) . '"'
                . selected($current, $one, false) . '>' . esc_html($one) . '</option>';
        }

        return '<tr><th scope="row">' . esc_html__('Default language', 'taxmod') . '</th><td>'
            . '<select name="neutral_locale">' . $options . '</select>'
            . ' '
            . HintMarkup::icon(
                __('A text written in this language counts as valid everywhere, and is stored without a language of its own. Other languages are stored beside it and win where they exist.', 'taxmod')
            )
            . '</td></tr>';
    }

    /** One size, chosen from what has been tried rather than typed. */
    private function sizeRow(string $option, string $label, int $now, string $why): string
    {
        $options = '';

        foreach (self::SIZES as $size) {
            $options .= '<option value="' . (int) $size . '"' . selected($now, $size, false) . '>'
                . esc_html($size . 'px') . '</option>';
        }

        return '<tr><th scope="row">' . esc_html($label) . '</th><td>'
            . '<select name="' . esc_attr(str_replace('taxmod_', '', $option)) . '">' . $options . '</select>'
            . ' ' . HintMarkup::icon($why) . '</td></tr>';
    }

    /**
     * Take the form and store it.
     *
     * ⚠️ **`CD-5` in order and no exception for an admin-only screen**: capability, nonce, validate,
     * sanitise, act. *A size arrives as characters and is checked against the offered list rather than
     * cast — `(int) 'huge'` is `0`, and a zero that arrived that way would silently make the tree
     * invisible.*
     */
    public function handlePost(): void
    {
        if (! current_user_can(Plugin::CAPABILITY)) {
            wp_die(esc_html__('You cannot change these.', 'taxmod'), '', ['response' => 403]);
        }

        check_admin_referer(self::ACTION, '_taxmod_nonce');

        update_option(NodesScreen::DEVELOPER_OPTION, isset($_POST['developer']), true);

        $locale = isset($_POST['neutral_locale'])
            ? sanitize_text_field(wp_unslash($_POST['neutral_locale']))
            : '';

        // ⚠️ Checked against what is installed, not merely sanitised: a locale nobody has would make
        // every label fall through to the node name with no way to see why.
        if ($locale !== '' && ($locale === get_locale() || in_array($locale, get_available_languages(), true))) {
            update_option(self::NEUTRAL_LOCALE, $locale, true);
        }

        foreach ([self::ICON_SIZE => 'icon_size', self::FONT_SIZE => 'font_size'] as $option => $field) {
            $asked = isset($_POST[$field]) ? absint($_POST[$field]) : 0;

            if (in_array($asked, self::SIZES, true)) {
                update_option($option, $asked, true);
            }
        }

        wp_safe_redirect(add_query_arg(
            ['page' => 'taxmod-settings', 'taxmod_saved' => '1'],
            admin_url('admin.php')
        ));

        exit;
    }

    private function notice(): string
    {
        if (! isset($_GET['taxmod_saved'])) {
            return '';
        }

        return '<div class="notice notice-success is-dismissible"><p>'
            . esc_html__('Saved.', 'taxmod') . '</p></div>';
    }

    /**
     * Which language stands for *everywhere*.
     *
     * ⚠️ **The site's own language until somebody declares one**, which is what the interim in
     * `NodesScreen` did — so nothing has to be migrated: an installation that never opens this screen
     * behaves exactly as it did.
     */
    public static function neutralLocale(): string
    {
        $declared = (string) get_option(self::NEUTRAL_LOCALE, '');

        if ($declared !== '') {
            return $declared;
        }

        $site = get_locale();

        return $site === '' ? 'en_US' : $site;
    }

    public static function inDeveloperMode(): bool
    {
        return (bool) get_option(NodesScreen::DEVELOPER_OPTION, false);
    }

    public static function defaultIconSize(): int
    {
        $size = (int) get_option(self::ICON_SIZE, 17);

        return in_array($size, self::SIZES, true) ? $size : 17;
    }

    public static function defaultFontSize(): int
    {
        $size = (int) get_option(self::FONT_SIZE, 15);

        return in_array($size, self::SIZES, true) ? $size : 15;
    }
}
