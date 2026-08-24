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

    /** Fewest occurrences that satisfy the attribute. Narrowing means **more** required. */
    case MultiplicityMin = 'multiplicity_min';

    /** Most occurrences allowed. Narrowing means **fewer**. */
    case MultiplicityMax = 'multiplicity_max';

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

    public function isBounding(): bool
    {
        return $this->direction() !== Narrowing::Free;
    }

    public function direction(): Narrowing
    {
        return match ($this) {
            self::MultiplicityMin, self::RangeMin              => Narrowing::OnlyUp,
            self::MultiplicityMax, self::RangeMax              => Narrowing::OnlyDown,
            self::Mandatory, self::Hide, self::ReadOnly        => Narrowing::OnceOnAlwaysOn,
            default                                            => Narrowing::Free,
        };
    }

    /** Whether a name belongs to the engine and may therefore not be used freely. */
    public static function isReserved(string $key): bool
    {
        return self::tryFrom($key) !== null;
    }
}
