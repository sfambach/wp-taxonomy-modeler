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
        $icon   = $control->icon !== '';

        return '<button class="button' . ($icon ? ' ' . self::ICON_ONLY : '') . '"'
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
            . ($icon
                ? '<span class="dashicons dashicons-' . RenderResult::escape($control->icon) . '"'
                    . ' aria-label="' . RenderResult::escape($control->label) . '"></span>'
                : RenderResult::escape($control->label))
            . '</button>';
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
