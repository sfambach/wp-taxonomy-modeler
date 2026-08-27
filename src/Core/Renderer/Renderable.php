<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * Something a renderer can draw. **The owner's `IRenderAble`, and it promises nothing.**
 *
 * ⚠️ **Why it has no members: *rendering is independent of the id*** — his sentence, and it settles the
 * shape. A renderer is **handed** everything it needs through {@see RenderContext}
 * ([D-159](../../../docs/NewConcept/90-decision-log.md)): the value, the resolved settings, the locale,
 * the field name, the surrounding markup. *So there is nothing left for the subject to promise, and an
 * interface that demanded a method would be demanding it for the convenience of the type checker.*
 *
 * ⚠️ **It is not {@see \Taxmod\Core\Model\Identity}, and conflating the two was the mistake.** The
 * owner: *you have now simply mixed two things together, `Identity` and `IRenderable`?* — and
 * [D-164](../../../docs/NewConcept/90-decision-log.md) is the proof rather than the argument: *records
 * do not share the model's identity space.* **A record is not an `Identity` and must still be
 * drawable**, so «has an id in the model space» cannot be the contract for «can be drawn».
 *
 * ```mermaid
 * flowchart TD
 *   R["Renderable · can be drawn"] --> I["Identity · id · version · name"]
 *   I --> N["Node"]
 *   I --> E["Relation"]
 *   R -.->|"S7"| V["a composed value · no model id at all"]
 *   R -.->|"D-106"| C["a record · its own id space"]
 * ```
 *
 * ⚠️ **[D-091](../../../docs/NewConcept/90-decision-log.md) wrote `render(Renderable $subject, …)` and
 * that reads as *only things with a model id may be drawn*** — which is exactly what would keep a
 * record and a composed value undrawable. *The signature says `Renderable` now; `Identity` implements
 * it, and the second implementor is the one S7 needs.*
 *
 * ⚠️ *One implementor today, and that is not a reason to wait. The owner asked for it twice and then
 * once more sharply — and the whole point of a contract is that the second party arrives later; naming
 * it afterwards would mean changing forty signatures at the moment S7 is hardest.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
interface Renderable
{
}
