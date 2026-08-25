<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * The frame of a node's page, in order — **and the order is the decision, not the taste**.
 *
 * [R20a](../../../docs/NewConcept/30-renderer.md#r20a--the-detail-view-is-not-a-special-screen):
 *
 * > **The order of the frame is decided and is not taste.** Top to bottom: **what acts** (buttons) ·
 * > **what cannot be changed** (a band of chips) · the **name**, *because that is what you change
 * > first* · **display** · the **attributes** · the **preview** · and last the **relations**,
 * > collapsed.
 *
 * ⚠️ **The owner walked that order out loud as the sequence in which a person actually works on a
 * node, and it was written down so a rebuild does not reshuffle it for looks.** This enum is that
 * sentence made into one fact: **declaration order is the frame order**, so nobody has to keep a
 * list somewhere in agreement with a list somewhere else.
 *
 * ```mermaid
 * flowchart TD
 *   A["1 · acts"] --> B["2 · fixed"] --> C["3 · name"] --> D["4 · display"]
 *   D --> E["5 · attributes"] --> F["6 · preview"] --> G["7 · relations, collapsed"]
 * ```
 *
 * @see docs/NewConcept/30-renderer.md
 */
enum PageSlot: string
{
    /** What acts — the buttons. */
    case Acts = 'acts';

    /** What cannot be changed: a band of chips. */
    case Fixed = 'fixed';

    /** The name, *because that is what you change first*. */
    case Name = 'name';

    /**
     * Display.
     *
     * ⚠️ **Labels are placed here and R20a does not say so.** A label is what a thing is *called*
     * in a role, which is presentation — but the frame names no slot for it, so this is an
     * assumption rather than a reading. *Recorded here rather than argued at the call site.*
     */
    case Display = 'display';

    /** The attributes. */
    case Attributes = 'attributes';

    /** The preview — [R21](../../../docs/NewConcept/30-renderer.md)–[R23](../../../docs/NewConcept/30-renderer.md), not built. */
    case Preview = 'preview';

    /** Last, and collapsed: the relations. */
    case Relations = 'relations';
}
