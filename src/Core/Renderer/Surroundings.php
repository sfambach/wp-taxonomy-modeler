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
     */
    public function __construct(
        public readonly ?string $refersTo = null,
        public readonly array $parts = [],
        public readonly array $actions = [],
        public readonly ?string $href = null,
        public readonly ?Submission $submits = null,
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
