<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * The simple data types that ship in the box.
 *
 * ⚠️ **They are content, not machinery.** A base scaffold ships and is imported once; afterwards
 * it is ordinary authored content (D-119), so these nodes are **not** framework-protected. A
 * model that never needs `color` may throw it away.
 *
 * ⚠️ **Every one of them earns its place through storage, rendering or ordering — never through
 * validation alone** (D-319). That filter is why `phone`, `ip`, `mac` and `ean` are validators
 * rather than types, and why `char` survived it (D-329): a `char` has renderings a text does not.
 *
 * ```mermaid
 * flowchart TD
 *   P["Primitives"] --> D["Data Types · a value inside the record"]
 *   P --> C["Constants · a reference to a node"]
 * ```
 *
 * @see docs/NewConcept/10-domain-core.md
 */
enum SimpleType: string
{
    /** Whole numbers. Never floating point — a price and a tolerance are exact (D-057). */
    case Int = 'int';

    /** Exact decimals, for the same reason. */
    case Decimal = 'decimal';

    /** Short strings and long ones alike, source code included (D-316). */
    case Text = 'text';

    /**
     * One character.
     *
     * ⚠️ Not a `text` of length one: it has a numeric identity behind it and can be shown as a
     * glyph, as ASCII, as Unicode or in a numeral system (D-329).
     */
    case Char = 'char';

    /** True or false, stored as `0` or `1` in the integer column (D-315). */
    case Bool = 'bool';

    /** An address, with a renderer that makes it clickable as `mailto:` (D-322). */
    case Email = 'email';

    /**
     * One type for date, time and both together, with a precision setting (D-291).
     *
     * ⚠️ Stored in UTC and shown in the site's timezone — except a plain date, which has no
     * timezone at all.
     */
    case DateTime = 'datetime';

    /** A colour value. */
    case Color = 'color';

    /**
     * A version number.
     *
     * ⚠️ It earns its place through **ordering** (D-321): `1.10` comes *after* `1.9`, where text
     * would put it before and every sorted list would be quietly wrong.
     */
    case Version = 'version';

    /** A reference to a node in the model. */
    case NodeRef = 'node_ref';

    /**
     * A reference to a WordPress user.
     *
     * ⚠️ Stored as **text**, like every opaque key of a foreign system (P4d) — the core sees a
     * string and knows nothing of WordPress (D-171).
     */
    case UserRef = 'user_ref';

    /**
     * Which typed column a value of this type lands in.
     *
     * ⚠️ **Typed columns, never one stringly value** cast in and out (D-071, D-074). Two types
     * sharing a column is normal — what would not be is a column that holds anything.
     */
    public function column(): string
    {
        return match ($this) {
            self::Int, self::Bool                                              => 'value_int',
            self::Decimal                                                      => 'value_decimal',
            self::DateTime                                                     => 'value_date',
            self::NodeRef                                                      => 'value_ref',
            self::Text, self::Char, self::Email, self::Color,
            self::Version, self::UserRef                                       => 'value_text',
        };
    }

    /** @return list<string> The node names, in the order they are seeded. */
    public static function names(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
