<?php declare(strict_types=1);

namespace Taxmod\Core\Model;


/**
 * What a node and an edge have in common: an **id** from the one space, and a **version**.
 *
 * ⚠️ **The owner designed this and it took a day to notice it was missing from the code.** He, from
 * memory: *I had defined that everything that can be rendered implements an interface, so a node and
 * an edge and other elements could do it.* **It is decided three times** —
 * [D-080](../../../docs/NewConcept/90-decision-log.md) says what it carries, *«`Identity` carries `id`
 * and `version` only. Named `Identity`, not `WPClassHead`, because `CD-1` forbids the core knowing
 * WordPress exists»*; [D-091](../../../docs/NewConcept/90-decision-log.md) puts it in the renderer's
 * signature; [D-339](../../../docs/NewConcept/90-decision-log.md) made it a **table**.
 *
 * ⚠️ **The table was built and the type was not, and that is worse than neither.** `taxmod_identities`
 * holds every id ever handed out, while `Node|Relation` stood in **forty** signatures — *a half-built
 * decision, where the built half makes the missing one look finished.*
 *
 * ⚠️ **Closed 2026-08-28: the union is down to none.** *Forty across eighteen files became two, and both
 * of those were stale docblock sentences rather than code. What carries the contract now is a pair:
 * **this class** for what a node and an edge share — `id`, `version`, `name` — and
 * {@see \Taxmod\Core\Renderer\Renderable} for what a renderer may ask of anything it draws. The owner
 * separated those two himself: «`Renderable` is an interface … functions in the interface guarantee the
 * interface», and «the `Identity` class would have everything that node and edge have in common —
 * **independent of the interface**».*
 *
 * ⚠️ **A parent class rather than an interface, on the owner's own design.**
 * [C86](../../../docs/NewConcept/10-domain-core.md): *a parent class is not strictly necessary — but it
 * is **simpler** if it carries all the attributes that relations and nodes have in common.* *I had
 * shrunk it to an interface and he corrected it back: «I would not see that as a mistake, that is how I
 * intended it initially.» Both shapes satisfy `render(Identity …)`; this is the one in the log.*
 *
 * ```mermaid
 * flowchart TD
 *   I["Identity · id · version"] --> N["Node · name · path"]
 *   I --> R["Relation · from · to · kind · name · position"]
 * ```
 *
 * ⚠️ **What it deliberately does not carry, and each has a reason that was measured.** **`name`**: both
 * have one and they mean different things — a node's is what an author works with
 * ([D-369](../../../docs/NewConcept/90-decision-log.md)), an edge's is the attribute's own name — *and
 * a renderer should not read either off the subject at all
 * ([D-159](../../../docs/NewConcept/90-decision-log.md)), which is what
 * [row 21](../../../docs/NewConcept/97-implementation-plan.md#the-working-list) is about.* **`position`**:
 * measured, it is on the **edge alone** — a node's order among its siblings is the position of its
 * *inheritance edge* ([D-435](../../../docs/NewConcept/90-decision-log.md),
 * [D-014](../../../docs/NewConcept/90-decision-log.md)). **`type`**: excluded by D-080 in as many words
 * — *a node's type **is** its inheritance branch, and a relation has its own `kind`.*
 *
 * ⚠️ *And attribution is not here either, on [C85](../../../docs/NewConcept/10-domain-core.md)'s
 * ruling: **who changed it** is a property of the change, so it lives in the changelog. A «last changed
 * by» on the base would record what the log already holds and would answer only the last change.*
 *
 * @see docs/NewConcept/10-domain-core.md
 */
abstract class Identity
{
    /**
     * @param int $id      Drawn from the one model space ([C11](../../../docs/NewConcept/10-domain-core.md)),
     *                     handed out once and never reissued
     *                     ([D-340](../../../docs/NewConcept/90-decision-log.md)). Meaningless, stable,
     *                     never resolved on.
     * @param int $version Raised on every change that actually changed something
     *                     ([D-282](../../../docs/NewConcept/90-decision-log.md)) — the whole of
     *                     optimistic locking, and the reason two people editing two settings of one
     *                     node do not silently overwrite each other.
     */
    /**
     * @param string $name What the thing is called, in the neutral base language.
     *
     * ⚠️ **On the owner's word, and it supersedes D-080's «only»**
     * ([D-436](../../../docs/NewConcept/90-decision-log.md)): *edge and node both have names and both
     * should be translatable.* **Both carry one and by [D-410](../../../docs/NewConcept/90-decision-log.md)
     * both carry labels for it** — so it passes C86's own test, *all the attributes that relations and
     * nodes have in common*. *D-080 was written before an attribute had labels; D-410 changed the
     * answer, not a preference.*
     *
     * ⚠️ **The mechanism is shared, the meaning is not.** A node's name is what an **author** works
     * with and the modelling tree draws it raw ([D-369](../../../docs/NewConcept/90-decision-log.md));
     * an attribute's name is the field's own word. *Same column, same translation path, two readings —
     * which is what a shared head is for.*
     *
     * ⚠️ *This does **not** settle [row 21](../../../docs/NewConcept/97-implementation-plan.md#the-working-list):
     * a **renderer** still must not read a name off its subject ([D-159](../../../docs/NewConcept/90-decision-log.md)),
     * and the chooser drawing `$subject->name` where a target's **label** belongs is a fault wherever
     * the property lives.*
     */
    protected function __construct(
        public readonly int $id,
        public readonly int $version,
        public readonly string $name,
    ) {
    }
}
