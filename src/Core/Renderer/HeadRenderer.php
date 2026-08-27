<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SimpleType;

/**
 * The detail head: what a node **is**, what can be **done** to it, and what cannot be changed.
 *
 * The owner's layout, given as a sketch and then **corrected by him after seeing it built**. First:
 * *form, 3 rows, 2 columns — left column Action, System, Name; right column the tool buttons and
 * parts, then constants like path, id and version; the name field's Rename goes and is saved by the
 * page save.* Then, looking at the result: *name into the first row, swap the «name» label for
 * «node», and actions are not their own row but behind the name field. The heading with the node name
 * goes.*
 *
 * And the question that settled where any of this belongs: *the head as we discussed it is still not
 * there — that is a renderer, right?* **Yes**, `R1`. It is the **fifth** hand-built panel to go
 * through it, after labels, settings, an attribute row and a record ([D-393](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ```mermaid
 * flowchart LR
 *   A["PageSlot::Acts + Name"] --> H[this renderer]
 *   F["PageSlot::Fixed"]       --> H
 *   H --> T["one table · 2 rows · 2 columns"]
 * ```
 *
 * ⚠️ **Two rows and not three, and the heading is gone.** *A page whose first row already holds an
 * editable name does not need the same name printed above it in larger type* — and once the acts sit
 * beside the name, `Acts` and `Name` are one row about **the node**. `Fixed` stays exactly what it
 * was: *what cannot be changed*.
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

    /**
     * The node itself: its name, and what can be done to it — **one row, not two**.
     *
     * ⚠️ **The owner collapsed his own three-row sketch after seeing it**: *name into the first row,
     * swap the «name» label for «node», and actions are not their own row but behind the name field.
     * The heading with the node name goes.* **He is right and the reason is that the heading was the
     * duplicate**: a page whose first row already holds an editable name does not need the same name
     * printed above it in larger type.
     *
     * ⚠️ *The label says **Node** and not «Name» because the row is no longer only the name — it is
     * the node: what it is called and what you may do to it, read across.*
     */
    public const NODE = 'node';

    /** Path, id, version, creation, last change, who changed it — read-only, every one derived. */
    public const SYSTEM = 'system';

    /**
     * Two rows, in this order.
     *
     * ⚠️ *What you **change** first, then what you may only **read** — which is why the constants
     * cannot be row one however useful they are to glance at.*
     *
     * @var list<string>
     */
    private const ORDER = [self::NODE, self::SYSTEM];

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

    public function fits(Identity $subject): bool
    {
        return $subject instanceof Node;
    }

    public function render(Identity $subject, RenderContext $context): RenderResult
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
