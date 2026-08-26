<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SimpleType;

/**
 * Picking a node in a **dialog** — the default chooser ([D-244](../../../docs/NewConcept/90-decision-log.md)).
 *
 * [D-108](../../../docs/NewConcept/90-decision-log.md) settled the shape — **two separate chooser
 * renderers, not one with a switch** ([D-018](../../../docs/NewConcept/90-decision-log.md)) — and made
 * *inline* the default. D-244 flipped the default on the owner's own account: *with search, tree
 * selection and so on we kept saying the dialog is probably the better alternative, also for searching
 * entries. So default to the dialog, but give the user the chance to do it inline where it really is
 * simple.*
 *
 * ```mermaid
 * flowchart LR
 *   C["the closed field · what is chosen now"] --> D["⌄ opens"]
 *   D --> T["the tree, walked · one cell per node"]
 * ```
 *
 * ⚠️ **A `<details>` and not a modal, and that is a deliberate floor rather than a shortcut.** A real
 * dialog needs scripting to open, to trap focus and to close on Escape; `<details>` gives *shut by
 * default, one click away* in plain HTML, and it degrades to an open list rather than to nothing.
 * *When the search inside it arrives — which is D-244's own reason for preferring a dialog — this
 * grows a real dialog and the summary line stays what it is.*
 *
 * ⚠️ **Shut by default, showing what is chosen.** That is the whole difference from
 * {@see InlineChooserRenderer}: a closed field needs only enough text to **recognise** — `Ω` — while
 * the open list needs enough to **tell apart** — `Ω — Ohm` ([D-263](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **The tree arrives walked and drawn** ([D-159](../../../docs/NewConcept/90-decision-log.md)): a
 * renderer reaches out to nothing, so the candidates are resolved before the descent and handed in as
 * a finished tree. *That is also why the same walker serves the modelling tree and this — one
 * hierarchy, two cells (D-367).*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class DialogChooserRenderer implements Renderer
{
    public const NAME = 'chooser-dialog';

    /** Where the walked tree of candidates is looked for in {@see Surroundings::$sections}. */
    public const CANDIDATES = 'candidates';

    public function name(): string
    {
        return self::NAME;
    }

    /**
     * ⚠️ **Edit only, and that is the point of it existing.** Showing a reference is
     * {@see ReferenceRenderer}'s job — *the target's label plus a link, and nothing behind it*
     * ([D-105](../../../docs/NewConcept/90-decision-log.md)). This is the half that had been missing,
     * which is why every reference field had been drawing as a fault.
     */
    public function supports(): array
    {
        return [Purpose::Edit];
    }

    /** @return list<SimpleType> A reference — what else would one pick a node for. */
    public function handles(): array
    {
        return [SimpleType::NodeRef];
    }

    public function fits(Node|Relation $subject): bool
    {
        return true;
    }

    public function render(Node|Relation $subject, RenderContext $context): RenderResult
    {
        $tree = $context->surroundings->sections[self::CANDIDATES] ?? null;

        if ($tree === null || $tree->body === '') {
            // ⚠️ **Nothing to choose from is said, not hidden** — R31: a control with no possible
            // answer is a fault in the model and drawing an empty box would let it pass for a field.
            return RenderResult::of(
                '<span class="taxmod-unsatisfiable">' . RenderResult::escape($tree?->title ?? '') . '</span>'
            );
        }

        return RenderResult::of(
            '<details class="taxmod-chooser">'
            // ⚠️ The closed field: **what is chosen**, and only enough of it to recognise (D-263).
            . '<summary class="taxmod-chooser-current">'
            . ($context->surroundings->refersTo === null
                ? '<span class="taxmod-nothing">—</span>'
                : RenderResult::escape($context->surroundings->refersTo))
            . '</summary>'
            . '<div class="taxmod-chooser-tree">' . $tree->body . '</div>'
            . '</details>'
        );
    }
}
