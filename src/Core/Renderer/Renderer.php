<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

use Taxmod\Core\Model\Identity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;

/**
 * Everything a person sees comes from one of these (sentence 14 of the core on one page).
 *
 * ⚠️ **One contract for both halves.** The subject is a node **or** an relation, because both are
 * identities drawn from one space (C11) and both carry a resolved renderer setting (D-091).
 * There is no second interface for relations, and none for pages either: a page is a rendered node.
 *
 * ⚠️ **`supports()` declares the purposes; the registry does not key on them** (D-217). One
 * lookup by type — display, edit and search are answered or **declined** by the same renderer.
 *
 * ⚠️ **A renderer never writes** (D-159), not even to tidy up a value it finds malformed. It is
 * handed what there is and returns a string.
 *
 * ```mermaid
 * flowchart LR
 *   S["the relation's own setting"] --> T["the target node's setting"]
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
     * Which simple types it can draw — **the registry key** (R14a).
     *
     * ⚠️ **The lookup is by type; the purpose rides in the context.** R14a is explicit that this
     * is the key and that [D-217](90-decision-log.md) superseded the *type **and** purpose*
     * reading. It is also what lets *one is marked default per type* be a fact the registry
     * holds rather than a convention the caller has to remember.
     *
     * An empty list means the renderer draws no simple type at all — a structural renderer such
     * as a table or a form, chosen for what a subject **is** rather than for what it holds.
     *
     * @return list<\Taxmod\Core\Model\SimpleType>
     */
    public function handles(): array;

    /**
     * Whether it is eligible for this subject at all — the registry's second job, at
     * configuration time: *which renderers may this node be given?*
     */
    public function fits(Renderable $subject): bool;

    public function render(Renderable $subject, RenderContext $context): RenderResult;
}
