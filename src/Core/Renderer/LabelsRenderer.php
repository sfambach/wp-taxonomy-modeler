<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SimpleType;

/**
 * What a subject is **called**, in one locale — the short roles on a line, the long one under it.
 *
 * The owner asked for the shape as well as the existence: *with the labels it would be nice if you
 * could pick the locale at the top and the texts were simply enterable, and the whole thing a bit
 * more compact — the short ones in one row, help underneath, a blank line between the short ones and
 * help; when new ones come along, a new row.*
 *
 * ```mermaid
 * flowchart LR
 *   L["locale · chosen once, at the top"] --> R["form · table · select · symbol"]
 *   R --> H["help · its own row"]
 * ```
 *
 * ⚠️ **The third hand-built panel to go through `R1`**, after the attribute table
 * ([D-376](../../../docs/NewConcept/90-decision-log.md)) and the settings panel
 * ([D-381](../../../docs/NewConcept/90-decision-log.md)). It printed `esc_html` straight into a table
 * and had no way to **enter** anything — a labels panel one can only read is a labels panel that
 * sends everybody to a form somewhere else.
 *
 * ⚠️ **One locale for the whole panel, not one per row.** A person works in a language, not in a
 * language per field, and a locale column would put the same choice on five rows. *It also makes the
 * fallback legible: what shows in a row is what that locale resolves to, and where nothing is stored
 * the chain answered ([D-020](../../../docs/NewConcept/90-decision-log.md)).*
 *
 * ⚠️ **Short and long are laid out differently because they are differently long.** `form`, `table`,
 * `select` and `symbol` are words; `help` is a sentence that *doubles as the tooltip and ends the
 * chain* ([D-209](../../../docs/NewConcept/90-decision-log.md)). Putting a sentence in a row of words
 * makes every word narrow.
 *
 * ⚠️ **A role that is not short is drawn on a row of its own, whatever it is called.** The rule reads
 * off {@see LabelSlot}, not off a list of names — so a sixth role added tomorrow lands somewhere
 * sensible instead of silently joining the word row.
 *
 * @see docs/NewConcept/40-i18n.md
 */
final class LabelsRenderer implements Renderer
{
    public const NAME = 'labels';

    /** The act that writes the whole panel at once. */
    public const WRITE = 'put_labels';

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    /** @return list<SimpleType> Empty: structural — it draws what a subject is called. */
    public function handles(): array
    {
        return [];
    }

    public function fits(Node|Relation $subject): bool
    {
        return true;
    }

    public function render(Node|Relation $subject, RenderContext $context): RenderResult
    {
        $rows = $context->surroundings->rows;

        if ($rows === []) {
            return RenderResult::of('');
        }

        $short = '';
        $long  = '';

        foreach ($rows as $row) {
            if (! $row instanceof LabelSlot) {
                continue;
            }

            if ($row->isLong) {
                $long .= $this->longRow($row, $context);

                continue;
            }

            $short .= $this->shortField($row, $context);
        }

        // ⚠️ **The locale sits at the head of the short row, on the owner's ask** — *then we save
        // space.* It is a `select` and not a form of its own, because the short fields live inside the
        // entry form and HTML forbids the nesting; it carries no `name`, so saving submits nothing
        // from it. *A control that moves, standing among controls that are written — which is why it
        // keeps its own heading rather than pretending to be a sixth role.*
        $inside = ($short === '' ? '' : '<div class="taxmod-labels-short" style="display:flex;gap:.8em;'
                . 'align-items:flex-end;flex-wrap:wrap;margin:.4em 0">'
                . $this->localePicker($context) . $short . '</div>')
            // ⚠️ The blank line the owner asked for, and it earns its place: it is the seam between
            // words and sentences, which is the one distinction this panel makes.
            . ($short !== '' && $long !== '' ? '<div style="height:.7em"></div>' : '')
            . $long;

        // ⚠️ **The locale picker sits *beside* the entry form and never inside it**, because HTML
        // forbids a form within a form — and because they mean different things: switching language
        // is navigation, and it must not carry half-typed texts along with it.
        return RenderResult::of('<div class="taxmod-labels">' . $this->entryForm($inside, $context) . '</div>');
    }

    /**
     * The one form that holds every field and the save button.
     *
     * ⚠️ **One form and one button for the whole panel** — the owner's rule for saving (*what is
     * really saved is the page, not the single value*), and here also the only shape that works: five
     * fields with five buttons has no answer to what Enter does.
     */
    private function entryForm(string $inside, RenderContext $context): string
    {
        $submits = $context->surroundings->submits;

        if ($context->purpose !== Purpose::Edit || $submits === null) {
            return $inside;
        }

        $fields = ControlMarkup::hidden($submits);

        return '<form method="post" action="' . RenderResult::escape($submits->action) . '">'
            . $fields . $inside . $this->acts($context) . '</form>';
    }

    /**
     * The locale, chosen once for the whole panel.
     *
     * ⚠️ **It is a navigation control and not a value**, so it submits on its own and carries no
     * texts with it — switching language must not save half-typed entries. *The chooser is handed in
     * as an ordinary drawn control, because which locales exist is a boundary fact (`CD-1`).*
     */
    private function localePicker(RenderContext $context): string
    {
        $picker = $context->surroundings->sections['locale'] ?? null;

        if ($picker === null) {
            return '';
        }

        return '<div class="taxmod-labels-locale"'
            . ' style="display:flex;flex-direction:column;gap:.15em;flex:0 0 8em">'
            . '<code style="font-size:.9em">' . RenderResult::escape($picker->title) . '</code>'
            . $picker->body
            . '</div>';
    }

    /**
     * One short role: its name above, its field below, sharing the line with its siblings.
     *
     * ⚠️ **A `symbol` gets a narrower column than a name does**, on the owner's ask — it holds `Ω`,
     * `C`, `St`, at most a couple of characters, and giving it the width of `Condensator` wastes the
     * line the whole row exists to save. *Read off {@see LabelSlot::$translatable}: a role that is
     * *the same in every language* is one fixed by a standard, and those are short by construction —
     * so the width follows a property of the role rather than a list of names (`CD-9`).*
     */
    private function shortField(LabelSlot $slot, RenderContext $context): string
    {
        $width = $slot->translatable ? 'flex:1 1 8em;min-width:7em' : 'flex:0 0 5em';

        $label = '<label style="display:flex;flex-direction:column;gap:.15em;' . $width . '">'
            . '<code style="font-size:.9em">' . RenderResult::escape($slot->role) . '</code>';

        if ($context->purpose !== Purpose::Edit) {
            return $label . '<span>' . RenderResult::escape($slot->shown) . '</span></label>';
        }

        return $label
            . '<input type="text" name="' . RenderResult::escape($slot->fieldName) . '"'
            . ' value="' . RenderResult::escape($slot->stored ?? '') . '"'
            // ⚠️ **The placeholder is what the chain answers** (D-020), so an empty field reads as
            // *nothing is stored here and something else answers* rather than as *this has no name*.
            // *I briefly suppressed it where the chain fell through to the node's own name, on the
            // theory that a `symbol` greyed with `Condensator` claims a symbol nobody wrote. The owner
            // had meant the **layout**, and the suppression cost more than it saved: on `form`,
            // `table` and `select` the node name is exactly the useful answer.*
            . ' placeholder="' . RenderResult::escape($slot->shown) . '"'
            // ⚠️ **The remark lives in the title and not under the box.** A visible note in one
            // column of a flex row makes that column taller and tips the whole line out of
            // alignment — which is what the owner's *almost perfect* was pointing at.
            . ($slot->note === '' ? '' : ' title="' . RenderResult::escape($slot->note) . '"')
            . ' style="width:100%">'
            . '</label>';
    }

    /** A long role — its own row, and a box that can hold a sentence. */
    private function longRow(LabelSlot $slot, RenderContext $context): string
    {
        $head = '<div class="taxmod-labels-long" style="display:flex;flex-direction:column;gap:.15em">'
            . '<code style="font-size:.9em">' . RenderResult::escape($slot->role) . '</code>';

        if ($context->purpose !== Purpose::Edit) {
            return $head . '<span>' . RenderResult::escape($slot->shown) . '</span></div>';
        }

        return $head
            . '<textarea name="' . RenderResult::escape($slot->fieldName) . '" rows="2"'
            . ' placeholder="' . RenderResult::escape($slot->shown) . '"'
            . ' style="width:100%">' . RenderResult::escape($slot->stored ?? '') . '</textarea>'
            . '</div>';
    }

    /** The buttons at the foot of the entry form. */
    private function acts(RenderContext $context): string
    {
        $buttons = '';

        foreach ($context->surroundings->actions as $control) {
            if (ControlMarkup::isAWord($control)) {
                continue;
            }

            // ⚠️ **Composed in one place** ({@see ControlMarkup}) — greyed rather than gone (D-370),
            // red only where something is taken away, and an icon-only button marked so no surface
            // has to guess. *There were four copies of this and the borderless rule reached one of
            // them, which is how boxes came back around the icons everywhere else.*
            $buttons .= ControlMarkup::button($control);
        }

        if ($buttons === '') {
            return '';
        }

        return '<div style="display:flex;gap:.2em;margin-top:.5em">' . $buttons . '</div>';
    }
}
