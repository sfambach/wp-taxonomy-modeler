<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * One node's cell, plus where it sits — what the **walker** needs and the **cell** must not know.
 *
 * ⚠️ **This is [D-367](../../../docs/NewConcept/90-decision-log.md)'s split made into a type.** The
 * tree renderer *walks the hierarchy and builds the nesting*; the node renderer *draws the node*.
 * So depth, whether a row has children and whether it is collapsed live **here**, beside a finished
 * cell — and the cell renderer never sees any of them. *That is what lets the same cell serve the
 * modelling tree, the trash and the chooser.*
 *
 * ⚠️ **`toggle` is a URL and arrives from the boundary** (`CD-1`), like every other address. Its
 * absence means *nothing to fold* — a leaf, or a surface that does not offer folding at all.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class DrawnRow
{
    public function __construct(
        public readonly int $depth,
        public readonly RenderResult $cell,
        public readonly bool $hasChildren = false,
        public readonly bool $collapsed = false,
        public readonly ?string $toggle = null,
        public readonly bool $highlighted = false,
    ) {
    }
}
