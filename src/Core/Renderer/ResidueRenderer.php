<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * The repair surface's list — **it owns the shape of what Cleanup shows**.
 *
 * The owner, by way of [D-247](../../../docs/NewConcept/90-decision-log.md): *Cleanup was meant for
 * tidying — nodes that have no connections any more, or settings that broke because something was
 * deleted.* [U24](../../../docs/NewConcept/20-interaction.md) calls it a **repair surface** rather
 * than a feature, and *never automatic*: so every line here has its own act.
 *
 * ```mermaid
 * flowchart LR
 *   B["the boundary · measures · words · nonce"] --> G["ResidueGroup"]
 *   G --> T["this · one block per source"]
 *   T --> M["ControlMarkup · the button"]
 * ```
 *
 * ⚠️ **A renderer and not markup glued together on the screen** (`R1`,
 * [D-463](../../../docs/NewConcept/90-decision-log.md)). *D-463's fifth line — «for specific
 * problems that are generic, solutions can be placed in the renderer parent» — is why the button is
 * {@see ControlMarkup}'s and the framing is this class's: **one place knows how a residue line
 * looks**, and the screen hands in facts.*
 *
 * ⚠️ **Not implementing {@see Renderer}, and that is the same call {@see ControlMarkup} made.** *The
 * contract is `render(Renderable, RenderContext)` — a **value of a type**, with settings resolved
 * and a purpose. A leftover row is neither: it has no type, no value and no chain, and forcing one
 * through that door would mean inventing a `Renderable` for a row that names an owner which no
 * longer exists.*
 *
 * @see docs/NewConcept/20-interaction.md
 */
final class ResidueRenderer
{
    /** Marks the list, so a stylesheet can hold what is common to it (D-463, «commonalities in CSS»). */
    public const CLASS_NAME = 'taxmod-residue';

    /**
     * @param list<ResidueGroup> $groups In the order the three sources are to be read.
     */
    public function render(array $groups): string
    {
        $markup = '';

        foreach ($groups as $group) {
            $markup .= '<div class="' . self::CLASS_NAME . '-group">'
                . '<h2>' . RenderResult::escape($group->title) . '</h2>'
                . '<p class="description">' . RenderResult::escape($group->explains) . '</p>'
                . $this->entries($group)
                . '</div>';
        }

        return '<div class="' . self::CLASS_NAME . '">' . $markup . '</div>';
    }

    /**
     * What is lying there — or the sentence that says nothing is.
     *
     * ⚠️ **One form per line and not one form around the list.** *A repair surface removes one thing
     * at a time by [D-247](../../../docs/NewConcept/90-decision-log.md) — «shown and removed
     * deliberately, never automatically» — and a single form with many buttons is one `Enter` away
     * from being a «tidy everything» nobody asked for.*
     */
    private function entries(ResidueGroup $group): string
    {
        if ($group->entries === []) {
            return '<p><em>' . RenderResult::escape($group->whenEmpty) . '</em></p>';
        }

        $markup = '';

        foreach ($group->entries as $entry) {
            $markup .= '<li style="display:flex;align-items:center;gap:.8em;padding:.3em 0">'
                . '<span style="flex:1;min-width:0">' . RenderResult::escape($entry->what) . '</span>'
                . $this->form($entry)
                . '</li>';
        }

        return '<ul style="margin:.5em 0 1.5em">' . $markup . '</ul>';
    }

    /**
     * ⚠️ *The hidden fields and the button are both {@see ControlMarkup}'s — it already owns that loop
     * for four renderers, and a fifth copy of it here is exactly the drift it was extracted to end.*
     */
    private function form(ResidueEntry $entry): string
    {
        return RenderResult::htmlTag('form', [
                'method' => 'post',
                'action' => $entry->submits->action,
                'style'  => 'margin:0;flex:none',
            ])
            . ControlMarkup::hidden($entry->submits)
            . ControlMarkup::button($entry->act)
            . '</form>';
    }
}
