<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * One thing a person can do to the subject — **described, not drawn**.
 *
 * ⚠️ **A renderer *can* build a button, and I had claimed otherwise.** The owner: *why can a
 * renderer not build buttons? that is not right.* He is correct, and the mistake was a confusion
 * worth naming: a renderer cannot **invent** the three WordPress-shaped values a control needs —
 * a **nonce**, a form **URL**, and a **translated** label (`CD-1`, `AR-2`) — but composing
 * `<button name=… value=…>` out of values it was given is string work, which is all a renderer
 * ever does.
 *
 * ```mermaid
 * flowchart LR
 *   B["the boundary<br/>nonce · URL · words · what is allowed"] --> C[Control]
 *   C --> R["the renderer<br/>builds the markup"]
 * ```
 *
 * ⚠️ **So the boundary hands in facts, not markup.** That is the difference between a renderer
 * that owns the shape of a row and one that concatenates somebody else's HTML — and `R1` wants the
 * first: *anything that shows model data goes through the renderer contract.*
 *
 * ⚠️ **What is *not* here is availability.** A control that may not be used is **left out**, never
 * greyed — the tree already says which rows cannot move ([U8](../../../docs/NewConcept/20-interaction.md)),
 * and a protected node simply has no delete ([D-194](../../../docs/NewConcept/90-decision-log.md)).
 * *Deciding that needs knowledge a renderer must not fetch (D-159), so the boundary decides by
 * omitting.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class Control
{
    /**
     * @param string $name  The form field it submits under.
     * @param string $value What it submits.
     * @param string $label What a person reads — **already translated**, because the text domain is
     *                      the boundary's (`AR-2`).
     * @param string $title The longer explanation, translated the same way. Empty for none.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        public readonly string $label,
        public readonly string $title = '',
    ) {
    }
}
