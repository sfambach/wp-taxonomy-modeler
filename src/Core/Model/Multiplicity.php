<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * How often an attribute may occur at one use site — and there are exactly four.
 *
 * ⚠️ **Four constants, not two numbers** (D-351). Two integer fields can express `3..7`, which
 * nothing in the model ever wanted, and they invite a pair that contradicts itself — `min = 5`
 * with `max = 2`. One key with four values cannot be wrong.
 *
 * ⚠️ **It belongs to the edge, never to the node.** A node describes a *thing* and a thing has
 * no multiplicity; an edge describes a *use of a thing*, and a use does.
 *
 * ## What "narrower" means here
 *
 * Not arithmetic — **containment**. Read each constant as the set of counts it allows, and one
 * multiplicity narrows another when its set fits inside the other's.
 *
 * ```mermaid
 * flowchart TD
 *   M["0..* · any number"] --> A["0..1 · at most one"]
 *   M --> B["1..* · at least one"]
 *   A --> E["1..1 · exactly one"]
 *   B --> E
 * ```
 *
 * ⚠️ **`0..1` and `1..*` are neither**, and that is the interesting part: going from one to the
 * other trades *may be absent* for *may be several*. It is a different bound, not a tighter one,
 * so under D-312 it is refused rather than quietly allowed.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
enum Multiplicity: string
{
    /** At most one, and it may be missing. */
    case ZeroToOne = '0..1';

    /** Exactly one — the narrowest of the four. */
    case ExactlyOne = '1..1';

    /** Any number, including none — the widest of the four. */
    case ZeroToMany = '0..*';

    /** At least one, and there may be many. */
    case OneToMany = '1..*';

    /** Whether an occurrence is required at all. */
    public function requiresOne(): bool
    {
        return $this === self::ExactlyOne || $this === self::OneToMany;
    }

    /** Whether more than one occurrence is allowed. */
    public function allowsMany(): bool
    {
        return $this === self::ZeroToMany || $this === self::OneToMany;
    }

    /**
     * Whether this is at least as narrow as `$other` — the test D-312 applies going down.
     *
     * Both halves must hold: the floor may only rise, and the ceiling may only fall. A pair
     * where one rises and the other rises too is incomparable, and returns false.
     */
    public function narrows(self $other): bool
    {
        $floorHeldOrRaised   = $this->requiresOne() || ! $other->requiresOne();
        $ceilingHeldOrLowered = ! $this->allowsMany() || $other->allowsMany();

        return $floorHeldOrRaised && $ceilingHeldOrLowered;
    }

    /**
     * What a person sees before labels exist.
     *
     * ⚠️ Deliberately the notation itself. `0..1` is not English and needs no translating,
     * which is exactly why it survives a locale change without a label (`AR-2`).
     */
    public function notation(): string
    {
        return $this->value;
    }
}
