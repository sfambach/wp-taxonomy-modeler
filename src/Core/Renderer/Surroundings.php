<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * What a renderer was told about **everything other than its own value**.
 *
 * ⚠️ **This exists because [D-159](../../../docs/NewConcept/90-decision-log.md) forbids a renderer
 * to reach out, and four different things then had to be handed in.** Each arrived on its own day
 * and for its own reason, and together they are one idea: *resolved before the descent, placed by
 * the renderer.*
 *
 * ```mermaid
 * flowchart LR
 *   D["the descent · resolves and draws"] --> S[surroundings]
 *   B["the boundary · builds controls"] --> S
 *   S --> R["the renderer · places them"]
 * ```
 *
 * | Field | Why a renderer cannot get it itself |
 * |---|---|
 * | `refersTo` | a **reference** draws its target's label ([D-105](../../../docs/NewConcept/90-decision-log.md)); resolving it is a query, and one per row is `CD-7`'s loop |
 * | `parts` | a **container** lays out members the descent drew ([R46](../../../docs/NewConcept/30-renderer.md), [D-366](../../../docs/NewConcept/90-decision-log.md)); it must not be the one asking |
 * | `actions` | a control carries a URL and a nonce — boundary facts (`CD-1`) — and *what may be done* depends on things a renderer must not fetch ([D-367](../../../docs/NewConcept/90-decision-log.md)) |
 *
 * ⚠️ **There was briefly a fourth — `subjectLabel`, what the node being drawn is called — and it is
 * gone.** The tree shows the node's **own name** ([D-369](../../../docs/NewConcept/90-decision-log.md)),
 * so the cell needs nothing handed in and `cellsFor()` saves a query. *A field nothing uses is a
 * field somebody will use wrongly; the chooser can ask for one back when it needs one.*
 *
 * ⚠️ **Grouped rather than left on {@see RenderContext}, which had grown to twelve parameters.**
 * The owner asked whether the core boundary was worth its friction; the honest answer was that most
 * of the friction is one unanswered question ([OQ-087](../../../docs/NewConcept/91-open-questions.md))
 * and the rest was **this shape**. *The chooser will want a fifth field — the set that may be
 * picked — and it belongs here rather than on the context.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class Surroundings
{
    /**
     * @param list<RenderedField> $parts   The members, already drawn, in the order the descent
     *                                     found them. A container regroups; it does not draw.
     * @param list<string>        $actions Finished controls to place with the subject, in order.
     */
    /**
     * @param string|null $href Where the subject is reached, when the surface has somewhere to go.
     *
     * ⚠️ **A URL is handed in, never built.** The core has no idea what an admin screen or a
     * permalink looks like (`CD-1`) — but wrapping a link around what it drew is ordinary markup,
     * so the renderer keeps deciding the **shape** of a row instead of handing that back too.
     */
    /**
     * @param list<Control>   $actions What can be done to the subject — **described**, so that the
     *                                 renderer builds the buttons rather than concatenating
     *                                 somebody else's markup.
     * @param Submission|null $submits Where those controls go, and the nonce that rides with them.
     * @param list<DrawnRow>          $rows     Drawn cells with their depth, for a **walker** — the
     *                                          same arrangement as `parts`, one level up: the tree
     *                                          nests what the cell drew ([D-367](../../../docs/NewConcept/90-decision-log.md)).
     * @param array<string, Section>  $sections Blocks of a node's page, keyed by {@see PageSlot} —
     *                                          the frame's **order** is the enum's, not this array's.
     * @param array<string, RenderedSetting> $configured Drawn settings **of the subject**, by key.
     *
     * ⚠️ **Its own field rather than squeezed into `parts`.** A part is a **member** of the subject —
     * an attribute of a node — while a setting *configures* the subject; the two are drawn alike and
     * mean different things, and one list holding both would make a container guess which it had.
     * *The docblock above predicted this field would be wanted and named the reason: the shape
     * belongs here, not on the context.*
     */
    /**
     * @param array<string, string> $options      What may be chosen, value ⇒ **already translated**
     *                                            label. This is the field the docblock above
     *                                            predicted: *the chooser will want a fifth field —
     *                                            the set that may be picked.*
     * @param bool                  $mayBeNothing Whether leaving it unanswered is itself a real
     *                                            answer.
     *
     * ⚠️ **`mayBeNothing` is a separate fact and not derivable from `options`**, which is exactly
     * what [R31b](../../../docs/NewConcept/30-renderer.md#r31b--the-rule-counts-possibilities-not-entries)
     * turns on: *the test is never how many rows are in the list but how many **outcomes** this
     * control can produce.* One entry plus *nothing* is two outcomes and a live control; one entry
     * without it is one and already decided. **A renderer counting only rows would grey out the very
     * case where a person still has a take-it-or-leave-it decision** — R28–R32's fourth row, which
     * the concept marks as where the rule must not be over-applied. [R29](../../../docs/NewConcept/30-renderer.md)
     * says where the answer comes from: the **multiplicity**, which is not a renderer's to resolve.
     */
    public function __construct(
        public readonly ?string $refersTo = null,
        public readonly array $parts = [],
        public readonly array $actions = [],
        public readonly ?string $href = null,
        public readonly ?Submission $submits = null,
        public readonly array $rows = [],
        public readonly array $sections = [],
        public readonly array $configured = [],
        public readonly array $options = [],
        public readonly bool $mayBeNothing = true,
        /**
         * Whether this reference points at a **record** rather than at a node.
         *
         * ⚠️ **A prepared fact, so no renderer has to ask** ([D-445](../../../docs/NewConcept/90-decision-log.md)):
         * the descent already resolved the edge's type, and `null` there means the target is not a
         * data type and not a constant — *`typeOf()`'s own words: «a `Model` target is a reference to
         * a **record**, which has no simple type of its own and no renderer either — it wants the
         * summary renderer ([D-106](../../../docs/NewConcept/90-decision-log.md))».* **Costs nothing:
         * the type was resolved for the whole form in one query before the descent began.**
         *
         * ⚠️ **Why it exists at all — the message was blaming the wrong thing.** *Measured
         * 2026-08-27 on a `resistance` attribute pointing at `Einheitenwert`: the fallback said «the
         * one set for this cannot draw a reference», which sends a person to the renderer control
         * where **nothing is wrong**. The renderer it needs does not exist yet. A fault that names
         * the wrong cause costs more than one that says «not built».*
         */
        public readonly bool $refersToARecord = false,
        /**
         * The `id` of the form a control belongs to, when it cannot sit inside it.
         *
         * ⚠️ **This exists because a real bug needed it and the owner found it**: *multiplicity is not
         * saved, or something else goes wrong changing 0..1 to 0..\** on `Bauteilliste`'s `Position`.
         * The attribute row is a `<tr>`, its multiplicity sits in one `<td>` and its acts build a
         * `<form>` in **another** — so the control was **outside** the form and submitted nothing.
         * *HTML forbids a form wrapping table rows, so the control has to name the form instead:
         * `form="…"`, which is plain HTML and needs no scripting.*
         *
         * ⚠️ *The same seam the page-head save button uses ([D-392](../../../docs/NewConcept/90-decision-log.md)),
         * pointing the other way: there a **button** stands outside its form, here a **field** does.*
         */
        public readonly string $formId = '',
    ) {
    }

    /**
     * The same surroundings around a different target — what a multi-valued reference does per row.
     *
     * ⚠️ **The label travels with the value.** Two occurrences of one reference point at two
     * different nodes, so carrying the first one's label into the second row would name it wrongly.
     */
    public function referringTo(?string $refersTo): self
    {
        return new self($refersTo, $this->parts, $this->actions, $this->href, $this->submits);
    }
}
