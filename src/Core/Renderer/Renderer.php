<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;

/**
 * Everything a person sees comes from one of these (sentence 14 of the core on one page).
 *
 * ⚠️ **One contract for both halves.** The subject is a node **or** an edge, because both are
 * identities drawn from one space (C11) and both carry a resolved renderer setting (D-091).
 * There is no second interface for edges, and none for pages either: a page is a rendered node.
 *
 * ⚠️ **`supports()` declares the purposes; the registry does not key on them** (D-217). One
 * lookup by type — display, edit and search are answered or **declined** by the same renderer.
 *
 * ⚠️ **A renderer never writes** (D-159), not even to tidy up a value it finds malformed. It is
 * handed what there is and returns a string.
 *
 * ```mermaid
 * flowchart LR
 *   S["the edge's own setting"] --> T["the target node's setting"]
 *   T --> A["its ancestors"] --> F["the fallback"]
 * ```
 *
 * That walk is D-079's, unchanged — the renderer choice is a setting like any other, and the
 * highest override wins.
 *
 * @see docs/NewConcept/30-renderer.md
 */
interface Renderer
{
    /**
     * The name this renderer is chosen by — the value that lands in the `renderer` setting.
     *
     * ⚠️ **A token, not a label.** It is stored in the model and compared, so it is never
     * translated; what a person reads in the choice list is a label like any other (`AR-2`).
     */
    public function name(): string;

    /**
     * Which purposes it can answer for.
     *
     * @return list<Purpose>
     */
    public function supports(): array;

    /**
     * Whether it is eligible for this subject at all — the registry's second job, at
     * configuration time: *which renderers may this node be given?*
     */
    public function fits(Node|Relation $subject): bool;

    public function render(Node|Relation $subject, RenderContext $context): RenderResult;
}
