<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SimpleType;

/**
 * The detail head: what a node **is**, what can be **done** to it, and what cannot be changed.
 *
 * The owner's layout, given as a sketch: *form, 3 rows, 2 columns — left column Action, System,
 * Name; right column the tool buttons and parts, then constants like path, id and version, creation,
 * last change, change owner; the name field's Rename goes and is saved by the page save.*
 *
 * And then, looking at a screen that still had none of it: *the head as we discussed it is still not
 * there — that is a renderer, right?* **Yes**, and that is the whole reason this class exists rather
 * than another block of markup in the screen: `R1`. It is the **fifth** hand-built panel to go
 * through it, after labels, settings, an attribute row and a record ([D-393](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ```mermaid
 * flowchart LR
 *   A["PageSlot::Acts"]  --> H[this renderer]
 *   F["PageSlot::Fixed"] --> H
 *   N["PageSlot::Name"]  --> H
 *   H --> T["one table · 3 rows · 2 columns"]
 * ```
 *
 * ⚠️ **The three rows are the three slots that already existed**, which is why this is a layout
 * change and not a new concept: `Acts` is *what acts — the buttons*, `Fixed` is *what cannot be
 * changed*, `Name` is *the name, because that is what you change first*. **The head draws them
 * together instead of as three loose bands**, and their meanings are unchanged.
 *
 * ⚠️ **The row labels arrive as section titles and are not written here.** *A left-hand column
 * saying «Action» is user-visible software text, so it goes through the text domain at the boundary
 * (`AR-2`) — the core has no `__()` and must not grow one (`CD-1`).*
 *
 * ⚠️ **A `<table>` and not a grid, because the owner asked for a table** and because that is what
 * this is: a label and a value, three times, read across. *A row whose right cell is empty is left
 * out rather than drawn hollow — a head with an empty band reads as a fault.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class HeadRenderer implements Renderer
{
    public const NAME = 'head';

    /** The buttons, the child field, the move target. */
    public const ACTION = 'action';

    /** Path, id, version, creation, last change, who changed it — read-only, every one derived. */
    public const SYSTEM = 'system';

    /** The name field. No Rename button: the page save writes it ([D-392](../../../docs/NewConcept/90-decision-log.md)). */
    public const NAME_ROW = 'name';

    /**
     * The order the rows are read in, and it is the owner's order.
     *
     * ⚠️ *Not alphabetical and not the enum's order — he said *Action, System, name*, and the reason
     * shows in the middle row: the constants sit between what you **do** and what you **type**,
     * where they can be glanced at without being in the way.*
     *
     * @var list<string>
     */
    private const ORDER = [self::ACTION, self::SYSTEM, self::NAME_ROW];

    public function name(): string
    {
        return self::NAME;
    }

    public function supports(): array
    {
        return [Purpose::Display, Purpose::Edit];
    }

    /** @return list<SimpleType> Empty: structural — it frames a node, it draws no value itself. */
    public function handles(): array
    {
        return [];
    }

    public function fits(Node|Relation $subject): bool
    {
        return $subject instanceof Node;
    }

    public function render(Node|Relation $subject, RenderContext $context): RenderResult
    {
        $rows = '';

        foreach (self::ORDER as $key) {
            $section = $context->surroundings->sections[$key] ?? null;

            // ⚠️ An absent row is absent, not empty. *The head of a framework node has no acts worth
            // offering, and drawing a hollow band there would read as something having gone wrong.*
            if ($section === null || trim($section->body) === '') {
                continue;
            }

            $rows .= '<tr>'
                . '<th scope="row" class="taxmod-head-label">'
                . RenderResult::escape($section->title)
                . '</th>'
                . '<td class="taxmod-head-' . RenderResult::escape($key) . '">'
                . $section->body
                . '</td>'
                . '</tr>';
        }

        if ($rows === '') {
            return RenderResult::of('');
        }

        return RenderResult::of(
            '<table class="taxmod-head"><tbody>' . $rows . '</tbody></table>'
        );
    }
}
