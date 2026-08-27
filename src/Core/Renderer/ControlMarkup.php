<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * One button, composed from a {@see Control} — **the only place that does it**.
 *
 * ⚠️ **It exists because there were four copies and they had already drifted.** The tree row, the
 * attribute row, the settings panel and the labels panel each built the same `<button>` from the same
 * fields, and the owner found the consequence: *in the node renderer you have put boxes around the
 * icons again — away with them … it seems to be the case throughout, no boxes around the icons.* The
 * borderless rule had been written for the tree, and the other three grew afterwards.
 *
 * ```mermaid
 * flowchart LR
 *   B["the boundary · a Control"] --> M[this] --> R["four renderers place it"]
 * ```
 *
 * ⚠️ **Not a base class and not a trait, because a renderer is not a kind of button.** A static
 * composer keeps the renderers free of an inheritance they would then all share for one method — and
 * it is `RenderResult::escape()` all the way down, so nothing here knows a surface.
 *
 * ⚠️ **An icon-only button says so in a class**, rather than the stylesheet guessing from its
 * contents. *The alternative was `:has(> .dashicons:only-child)`, which works and hides the rule in
 * the paint; the renderer already knows, so it states it.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ControlMarkup
{
    /** Marks a button whose whole content is an icon, so the surface can strip its box. */
    public const ICON_ONLY = 'taxmod-icon-button';

    /**
     * @param bool|null $available Overrides the control's own answer, for a row that decides per
     *                            row — the settings panel greys `Reset` where nothing was set here.
     */
    public static function button(Control $control, ?bool $available = null): string
    {
        $usable = $available ?? $control->available;

        // ⚠️ **«Icon-only» is about the *shape*, not about which font drew it.** Measured on the real
        // page after the first version of this: the save button lost `taxmod-icon-button` and would
        // have got its box back among 405 flat neighbours — *because a glyph button is icon-only in
        // every way that matters to the layout, and only `face()` cares which of the two it is.*
        $bare = $control->icon !== '' || $control->glyph !== '';
        $icon = $control->icon !== '';

        // ⚠️ **One place builds every button, and it took four hand-written ones to get here.** The
        // owner: *every time new buttons appear they look odd again — is there a button renderer?* *He
        // is right about the symptom and about the cure: `Add`, the page save, `Move here` and
        // `add_attribute` were written out on the screen, so each one carried whatever classes whoever
        // wrote it remembered — which is how the save button ended up with `button-primary` **and** the
        // class that exists to take a background away.*
        //
        // ⚠️ *`leads` and `form` arrive as **facts** and become markup here, the same division
        // `destroys` already had: the surface says which act a person came for and which panel a button
        // submits; what that looks like is decided once, in this method.*
        // ⚠️ **An icon button is never the prominent one, and that is enforced here rather than
        // remembered.** *`button-primary` paints a solid background; `taxmod-icon-button` exists to
        // take one away. The two together are what made the diskette blue among flat neighbours — so
        // the combination is now unrepresentable instead of merely discouraged.*
        return '<button class="button' . ($control->leads && ! $bare ? ' button-primary' : '')
            . ($bare ? ' ' . self::ICON_ONLY : '') . '"'
            . ($control->form === '' ? '' : ' form="' . RenderResult::escape($control->form) . '"')
            . ' name="' . RenderResult::escape($control->name) . '"'
            . ' value="' . RenderResult::escape($control->value) . '"'
            . ($control->title === '' ? '' : ' title="' . RenderResult::escape($control->title) . '"')
            . ($usable ? '' : ' disabled')
            // ⚠️ **Red only where something is taken away**, and the fact arrives as `destroys`
            // rather than as a colour — so the one control that must never be clicked by accident
            // looks the same on every surface.
            . ' style="color:' . ($control->destroys ? '#b32d2e' : '#1d2327')
            . ($usable ? '' : ';opacity:.35') . '">'
            // ⚠️ **An icon replaces the label but never the accessible name** — the label is what a
            // screen reader is left with, and a button with neither is a shape with no meaning.
            . self::face($control, $icon)
            . '</button>';
    }

    /**
     * What a button shows: a Dashicon, else a chosen character, else its words.
     *
     * ⚠️ **An icon replaces the label but never the accessible name** — the label is what a screen
     * reader is left with, and a button with neither is a shape with no meaning.
     *
     * ⚠️ *The middle rung is the page save and its `💾`: the icon font has no diskette and
     * `dashicons-saved` is a tick, which the owner spotted. So the glyph is drawn and the label still
     * travels as the name — the alternative was an emoji **as** the label, which would have made
     * «floppy disk» the name of the save button.*
     */
    private static function face(Control $control, bool $icon): string
    {
        if ($icon) {
            return '<span class="dashicons dashicons-' . RenderResult::escape($control->icon) . '"'
                . ' aria-label="' . RenderResult::escape($control->label) . '"></span>';
        }

        if ($control->glyph !== '') {
            return '<span aria-label="' . RenderResult::escape($control->label) . '">'
                . RenderResult::escape($control->glyph) . '</span>';
        }

        return RenderResult::escape($control->label);
    }

    /**
     * Every hidden field a submission carries.
     *
     * ⚠️ *The other half of the same duplication — four renderers spelling out the same loop over
     * `Submission::$hidden`.*
     */
    public static function hidden(Submission $submits): string
    {
        $fields = '';

        foreach ($submits->hidden as $name => $value) {
            $fields .= '<input type="hidden" name="' . RenderResult::escape($name)
                . '" value="' . RenderResult::escape($value) . '">';
        }

        return $fields;
    }

    /**
     * Whether this control is a word travelling with the buttons rather than a button.
     *
     * ⚠️ *A shortcut around [OQ-087](../../../docs/NewConcept/91-open-questions.md) — the core cannot
     * make a word, so the boundary sends words in the same list. It had to be skipped in four places
     * and was forgotten in one of them, which is how «here» and «not defined» appeared as buttons.*
     */
    public static function isAWord(Control $control): bool
    {
        return str_starts_with($control->name, 'word:');
    }
}
