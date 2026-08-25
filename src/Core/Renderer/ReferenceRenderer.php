<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\SimpleType;

/**
 * What a reference looks like: **the target's name, and nothing behind it**
 * ([D-105](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **It bounds the load, not only the display** ([R58](../../../docs/NewConcept/30-renderer.md#owner-statement--2026-08-22-thirteenth-pass-the-reference-renderer)).
 * That is the point of it being the default for an aggregation edge: a supplier on a part draws one
 * label, not a whole supplier — so a parts list of five hundred rows does not pull five hundred
 * suppliers with everything they own. *A renderer that shows less also asks for less, and the guard
 * against a runaway descent becomes a backstop rather than the mechanism.*
 *
 * ⚠️ **The label is handed in, never fetched.** A renderer reaches out to nothing (D-159), so the
 * descent resolves every referenced node's label in one query beforehand and puts it in the context.
 * The chain behind that label is the ordinary one — role, number, locale, then the node's own name,
 * which always exists (D-022).
 *
 * ⚠️ **The link of *label plus a link* is not built, and the reason is not laziness.** A URL belongs
 * to a surface — an admin screen, a front-end permalink — and the core has none and may not reach
 * for one (`CD-1`). How a core renderer emits something a surface has to complete is the same
 * question as how it emits a **word**: [OQ-087](../../../docs/NewConcept/91-open-questions.md), now
 * with two symptoms instead of one.
 *
 * ⚠️ **Display only, deliberately.** Changing a reference means **picking** a node, which is the
 * chooser — decided ([D-244](../../../docs/NewConcept/90-decision-log.md)) and not built. Declining
 * the edit purpose is how that gap stays visible instead of being papered over with a box somebody
 * would type an id into.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ReferenceRenderer extends TypedFieldRenderer
{
    public const NAME = 'reference';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        return [SimpleType::NodeRef];
    }

    /** @return list<Purpose> */
    public function supports(): array
    {
        return [Purpose::Display];
    }

    protected function display(RenderContext $context): string
    {
        if ($context->value->isNothing()) {
            return $this->shown('');
        }

        // ⚠️ **A reference whose label never arrived is drawn as a fault, not as an id.** It means
        // the target is gone, or the descent did not resolve it — and a bare number on screen is
        // the sort of thing that gets copied into a spreadsheet as if it meant something.
        if ($context->surroundings->refersTo === null) {
            return '<span class="taxmod-value taxmod-dangling">'
                . RenderResult::escape('#' . (string) $context->value->reference)
                . '</span>';
        }

        return $this->shown(RenderResult::escape($context->surroundings->refersTo));
    }

    protected function input(RenderContext $context): string
    {
        // Unreachable: the edit purpose is declined above, so the descent never asks.
        return $this->display($context);
    }
}
