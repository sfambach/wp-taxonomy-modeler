<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * What a value holds — in a typed column, never one stringly value cast in and out (D-071).
 *
 * ⚠️ **Nothing is a value, and it is not the same as absence** (D-266). A row holding nothing
 * says *deliberately nothing here*, and it **stops** changes at the base from arriving. A row
 * that is gone says *inherit again*. Losing that distinction loses the ability to say
 * **here there should be nothing**, and it is noticed only when a change somewhere above
 * surfaces where nobody wanted it.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class TypedValue
{
    private function __construct(
        public readonly ?int $int = null,
        public readonly ?string $decimal = null,
        public readonly ?string $text = null,
        public readonly ?string $date = null,
        public readonly ?int $reference = null,
    ) {
    }


    /**
     * Rebuild from the five typed columns. Storage is the one caller that legitimately knows
     * all of them at once — everywhere else a value is made by naming what it is.
     */
    public static function fromStorage(
        ?int $int,
        ?string $decimal,
        ?string $text,
        ?string $date,
        ?int $reference,
    ): self {
        return new self($int, $decimal, $text, $date, $reference);
    }

    public static function ofInt(int $value): self
    {
        return new self(int: $value);
    }

    /** Exact decimals as a string — never floating point (D-057). */
    /**
     * An exact decimal, **as it arrived**.
     *
     * ⚠️ **Nothing is normalised here, and I tried to.** Reading a stored `2.7` back as
     * `2.7000000000` is wrong on screen, so I trimmed trailing zeros in this constructor — and a test
     * caught it: *`2.50` must not come back as `2.5`* ([D-057](../../../docs/NewConcept/90-decision-log.md)).
     * **The test is right and the fix was in the wrong place.** What somebody typed is theirs; the ten
     * zeros are a **storage artefact** of `decimal(30,10)`, so they are trimmed where the padded string
     * arrives — in the repository — and never here.
     *
     * ⚠️ *That the scale a person typed is not preserved through storage at all is true and is a
     * different question: [OQ-085](../../../docs/NewConcept/91-open-questions.md).*
     */
    public static function ofDecimal(string $value): self
    {
        return new self(decimal: $value);
    }

    public static function ofText(string $value): self
    {
        return new self(text: $value);
    }

    public static function ofDate(string $value): self
    {
        return new self(date: $value);
    }

    /** Booleans are integers, `0` or `1` (D-315). */
    public static function ofBool(bool $value): self
    {
        return new self(int: $value ? 1 : 0);
    }

    public static function ofReference(int $nodeId): self
    {
        return new self(reference: $nodeId);
    }

    /** Deliberately nothing — the row exists and holds no value. */
    public static function nothing(): self
    {
        return new self();
    }

    public function isNothing(): bool
    {
        return $this->int === null
            && $this->decimal === null
            && $this->text === null
            && $this->date === null
            && $this->reference === null;
    }

    public function asBool(): bool
    {
        return $this->int === 1;
    }

    public function equals(self $other): bool
    {
        return $this->int === $other->int
            && $this->decimal === $other->decimal
            && $this->text === $other->text
            && $this->date === $other->date
            && $this->reference === $other->reference;
    }

    /** For a message or a log line — never for storage or comparison. */
    public function describe(): string
    {
        return match (true) {
            $this->isNothing()             => '(nothing)',
            $this->int !== null            => (string) $this->int,
            $this->decimal !== null        => $this->decimal,
            $this->date !== null           => $this->date,
            $this->reference !== null      => '→ ' . $this->reference,
            default                        => (string) $this->text,
        };
    }
}
