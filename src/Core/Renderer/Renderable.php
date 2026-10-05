<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * The agreement that an object may be rendered — **and the methods are the agreement**.
 *
 * ⚠️ **The owner's own words, and they correct a marker interface I had built instead:** *«`Renderable`
 * is an interface, it is the agreement that an object may be rendered. **Functions in the interface
 * guarantee the interface** — for example `getLabel()`, `getContent()` and a few more; that guarantees
 * a renderer can render them.»*
 *
 * ⚠️ **Why a marker was wrong.** I had argued that *rendering is independent of the id*, therefore the
 * interface should promise nothing. **The first half is his and it is right; the conclusion was mine and
 * it was not** — independence from the **id** says nothing about independence from a **label** or from
 * **content**. *A contract that guarantees nothing cannot guarantee that a renderer can draw what it is
 * handed, which is the entire purpose of having one.*
 *
 * ⚠️ **This does not weaken [D-159](../../../docs/NewConcept/90-decision-log.md) — it is how D-159 gets
 * kept.** *A renderer fetches nothing*: it asks the object it was handed, and the object was prepared
 * before the descent began. **The renderer still reaches for no repository**; what changes is that it
 * stops reading raw properties off a model class and asks a question instead.
 *
 * ```mermaid
 * flowchart LR
 *   R["a renderer"] -->|asks| A["label()"]
 *   R -->|asks| C["content()"]
 *   R -.->|never| D["a repository"]
 * ```
 *
 * ⚠️ **Named without the `get` prefix**, which is this codebase's convention everywhere else
 * (`name()`, `notation()`, `handles()`) — *his `getLabel()` and `getContent()` are these two.*
 *
 * ⚠️ *«And a few more» is deliberately not guessed. Two are asked for and two are here; a third arrives
 * when a renderer needs it, because an interface grown ahead of its callers guarantees things nobody
 * checks (`PR-4`).*
 *
 * @see docs/NewConcept/30-renderer.md
 */
interface Renderable
{
    /**
     * The word a person reads for this thing.
     *
     * ⚠️ *Not necessarily its `name`: [D-105](../../../docs/NewConcept/90-decision-log.md) wants a
     * reference drawn as its **target's label**, and [row 21](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)
     * is open because a chooser draws the raw database column instead. **A method is what makes that
     * fixable in one place** — a property could only ever return the column.*
     */
    public function label(): string;

    /**
     * What it holds, as characters — empty where it holds nothing.
     *
     * ⚠️ *Empty is a real answer and not a gap: a node and an relation **have** no content of their own,
     * which is why the value of a field travels in {@see RenderContext} today. **That is the seam S7
     * closes**: a composed value is a renderable that answers this properly, and it has no model id at
     * all ([D-232](../../../docs/NewConcept/90-decision-log.md)) — which is exactly why the parameter
     * could not stay `Identity`.*
     */
    public function content(): string;
}
