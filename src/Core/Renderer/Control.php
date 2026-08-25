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
 * ⚠️ **Availability is carried here, not expressed by leaving the control out**
 * ([D-370](../../../docs/NewConcept/90-decision-log.md)).
 * [U8](../../../docs/NewConcept/20-interaction.md) said the opposite — *not greyed out, **absent***
 * — and the owner reversed it so that **the row of buttons looks the same everywhere**. *At four
 * controls in fixed places the eye learns a position; with omission the bin slides left on every
 * row that cannot move up.* It also brings this in step with
 * [R28–R32](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete), whose own table
 * says **disabled** and **greyed** rather than gone.
 *
 * ⚠️ *Deciding availability still needs knowledge a renderer must not fetch (D-159) — a protected
 * node, the last child — so the **boundary** decides and states it here.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class Control
{
    /**
     * @param string $name      The form field it submits under.
     * @param string $value     What it submits.
     * @param string $label     What a person reads — **already translated**, because the text domain
     *                          is the boundary's (`AR-2`).
     * @param string $title     The longer explanation, translated the same way. Empty for none.
     * @param bool   $available Whether it can be used now. A disabled button submits nothing, so
     *                          keeping it is a matter of layout and never of safety.
     * @param bool   $destroys  Whether the act takes something away.
     *
     * ⚠️ **`destroys` is a fact about the act, not a colour.** The boundary knows what an action
     * does; how that reads on screen is the renderer's — which is why the flag is here and the red
     * is over there. *Otherwise every surface would pick its own red, and the one control that must
     * never be clicked by accident would look different in each of them.*
     */
    public function __construct(
        public readonly string $name,
        public readonly string $value,
        public readonly string $label,
        public readonly string $title = '',
        public readonly bool $available = true,
        public readonly bool $destroys = false,
    ) {
    }
}
