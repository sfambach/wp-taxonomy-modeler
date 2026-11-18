<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Description\LabelsPanel;
use Taxmod\Core\Model\Identity;
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
 * ⚠️ **The panel does not have to own its form.** Handed a {@see Surroundings::$formId} it draws none
 * and every field names the page's instead — the owner's *labels should be saved with the page too*,
 * which is [D-392](../../../docs/NewConcept/90-decision-log.md) reaching the last panel that still had
 * a save button of its own. *See {@see self::entryForm()} for why that is a move and not an addition.*
 *
 * @see docs/NewConcept/40-i18n.md
 */
final class LabelsRenderer extends RendererNode
{
    public const NAME = 'labels';

    // ⚠️ *`WRITE = 'put_labels'` stand hier und ist weg: seit die Texte im Seitenformular liegen
    // ([D-488](../../../docs/NewConcept/90-decision-log.md)) und der eigene Knopf des Bereichs
    // entfernt ist, schickte **niemand** diesen Akt mehr ab. **Eine Konstante für einen Akt, den es
    // nicht gibt, ist toter Code** — und wer den Bereich in ein Formular hängen will, nennt jetzt
    // dasselbe `put_setting` wie die Einstellungen daneben.*

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    /** @return list<SimpleType> Empty: structural — it draws what a subject is called. */
    public function handles(): array
    {
        return [];
    }

    public function fits(Renderable $subject): bool
    {
        return true;
    }

    public function render(Renderable $subject, RenderContext $context): RenderResult
    {
        $rows = $context->surroundings->rows;

        if ($rows === []) {
            return RenderResult::nothing();
        }

        $slots = array_values(array_filter($rows, static fn (mixed $row): bool => $row instanceof LabelSlot));
        $u     = $context->surroundings;
        $wahl  = $u->sections['locale'] ?? null;
        $edit  = $context->purpose === Purpose::Edit && $u->submits !== null;

        return RenderResult::described(new LabelsPanel(
            $slots,
            $context->purpose === Purpose::Edit,
            $wahl?->title,
            $wahl === null ? null : ($wahl->piece ?? $wahl->body),
            $u->formId,
            $edit ? $u->submits->action : null,
            $edit ? $u->submits->hidden : [],
            array_values(array_filter($u->actions, static fn (Control $c): bool => ! $c->isAWord()))
        ));
    }
}
