<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * The settings the engine owns — and which way each of them may move down the chain.
 *
 * ⚠️ **These names are reserved** (D-084). There is no `scope` column and no prefix: some keys
 * are the engine's, so an author cannot define a setting called `hide` and silently break
 * rendering. Everything else is a free key belonging to whoever made it.
 *
 * ⚠️ **Bounding settings may only be tightened downwards; choosing settings are free**
 * (D-312). The reason is not tidiness: **a restriction that may be reopened anywhere says
 * nothing when it is read.** To know what is allowed you would have to inspect every use site,
 * and at five hundred attributes the model is then only locally readable.
 *
 * ```mermaid
 * flowchart LR
 *   B["bounding · what is possible"] -->|narrower only| D["down the chain"]
 *   C["choosing · which one inside the bounds"] -->|free| D
 * ```
 *
 * @see docs/NewConcept/10-domain-core.md
 */
enum SettingKey: string
{
    // Bounding — they limit what is possible.

    /**
     * How often the attribute may occur — one of exactly four values (D-351).
     *
     * ⚠️ **Edge-only**, and the only key that is: a node describes a thing and a thing has no
     * multiplicity. See {@see Multiplicity} for what *narrower* means among the four.
     */
    case Multiplicity = 'multiplicity';

    /** ⚠️ Once an ancestor declares it, it stays for every descendant (D-311). */
    case Mandatory = 'mandatory';

    /** Hidden here and below. Once hidden, never revealed further down. */
    case Hide = 'hide';

    /** Fixed here and below. Once fixed, never unfixed further down. */
    case ReadOnly = 'read_only';

    /** Smallest permitted value. Narrowing means **higher**. */
    case RangeMin = 'range_min';

    /** Largest permitted value. Narrowing means **lower**. */
    case RangeMax = 'range_max';

    // Choosing — they pick within the bounds.

    /** ⚠️ A default is not a bound but a choice inside the permitted set, so it stays free. */
    case DefaultValue = 'default';

    case Renderer = 'renderer';
    case Converter = 'converter';
    case Icon = 'icon';
    case Order = 'order';


    /**
     * Whether a key says something only a **use** can have.
     *
     * ⚠️ **The asymmetry runs one way** ([50 Persistence](../../../docs/NewConcept/50-wordpress-persistence.md)):
     * everything sayable about a node is also sayable about one use of it, and the reverse is
     * not true. A node describes a *thing*, an edge describes a *use of a thing* — and a thing
     * has no multiplicity, while a use of it does.
     *
     * ⚠️ **This is about where a key applies, not a second mechanism.** Multiplicity still
     * inherits down the chain and is still narrowable: a subtype may tighten `0..1` to `1`.
     */
    public function isEdgeOnly(): bool
    {
        return $this === self::Multiplicity;
    }
    public function isBounding(): bool
    {
        return $this->direction() !== Narrowing::Free;
    }

    public function direction(): Narrowing
    {
        return match ($this) {
            self::Multiplicity                          => Narrowing::BySubset,
            self::RangeMin                              => Narrowing::OnlyUp,
            self::RangeMax                              => Narrowing::OnlyDown,
            self::Mandatory, self::Hide, self::ReadOnly => Narrowing::OnceOnAlwaysOn,
            default                                     => Narrowing::Free,
        };
    }

    /** Whether a name belongs to the engine and may therefore not be used freely. */
    public static function isReserved(string $key): bool
    {
        return self::tryFrom($key) !== null;
    }
}
