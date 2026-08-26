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

    /**
     * What an attribute means when nobody has said — **`0..1`**, on the owner's word.
     *
     * ⚠️ **There is no such thing as an attribute without a multiplicity**, so *unset* had to mean
     * something and it was meaning nothing: the chooser offered a blank option and the screen showed
     * an em dash. The owner: *multiplicity may not be empty, the default is `0..1`.*
     *
     * ⚠️ **Not seeded onto every edge, because settings are sparse**
     * ([D-015](../../../docs/NewConcept/90-decision-log.md)). An absent row **means** this rather
     * than being a gap to fill — writing `0..1` onto every attribute would put a fact in a thousand
     * places and make *nobody has narrowed this* indistinguishable from *somebody chose the widest
     * option*.
     *
     * ⚠️ *`0..1` and not `1`: the widest of the two single-valued forms. A default that **required** a
     * value would make every new attribute mandatory the moment it is created, which is a rule
     * nobody asked for arriving through a default.*
     */
    public static function standard(): self
    {
        return self::ZeroToOne;
    }

    /**
     * Read a stored multiplicity, falling back to the standard.
     *
     * ⚠️ **One place, because the fallback is worth exactly nothing if half the callers skip it.**
     * A resolved setting may be absent, empty, or hold something an import wrote; all three mean
     * *nobody said*, and `from()` would throw on the last two.
     */
    public static function fromSetting(?string $stored): self
    {
        if ($stored === null || $stored === '') {
            return self::standard();
        }

        return self::tryFrom($stored) ?? self::standard();
    }

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
     *
     * ⚠️ **Exactly one reads `1`, not `1..1`** — the owner's ask, and the stored value is
     * untouched. *`1..1` is a range whose ends happen to meet, which is a sentence about ranges;
     * `1` is what a person means.* UML writes it that way for the same reason, and the docblock of
     * {@see SettingKey::Multiplicity} already said *a subtype may tighten `0..1` to `1`* — so the
     * screen was the odd one out, not this.
     *
     * ⚠️ **Shown, never parsed.** Storage stays `1..1`, because the value is the enum's and a
     * display shortening must not become a second spelling the database has to know about.
     */
    public function notation(): string
    {
        return $this === self::ExactlyOne ? '1' : $this->value;
    }
}
