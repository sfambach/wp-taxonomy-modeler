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

    /**
     * How coarsely a numeric control moves — the third of R17's triple.
     *
     * ⚠️ **Named by the concept and therefore reserved.** [R17](../../../docs/NewConcept/30-renderer.md#r12r17)
     * says *integer and double **nodes** need min, max and step as settings*, in one breath. The
     * first two are `range_min` and `range_max`; leaving the third as a free key would have made
     * one of three siblings an outsider, and an author could then define `step` to mean something
     * else on the very nodes that use it.
     *
     * ⚠️ **Choosing, not bounding, and the reason is precise: nothing validates it.** A step of
     * five does not make seven unstorable — an import, a data pack or a computed value will write
     * seven and no rule is broken. It says how the **control** moves, not what the model permits,
     * and [D-312](../../../docs/NewConcept/90-decision-log.md)'s narrowing rule exists for what is
     * *allowed*: a restriction that may be reopened anywhere says nothing when it is read. **A
     * granularity says nothing about what is allowed in the first place**, so there is nothing to
     * protect from being reopened.
     */
    case RangeStep = 'range_step';

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

    /**
     * What this key's value looks like, so a control can be drawn for it.
     *
     * ⚠️ **`icon` is `Words` provisionally, and that is a gap rather than an answer.**
     * [D-251](../../../docs/NewConcept/90-decision-log.md) says the tree row draws a node's icon
     * *where one is set* and nothing says **what** an icon is — a symbol name, a media reference, a
     * character. Treated as characters until it is decided, which is the least it can be.
     *
     * @see SettingShape
     */
    public function shape(): SettingShape
    {
        return match ($this) {
            self::Mandatory, self::Hide, self::ReadOnly => SettingShape::Switch,
            self::Order                                => SettingShape::Whole,
            self::Multiplicity                         => SettingShape::OneOfFour,
            self::Renderer, self::Converter            => SettingShape::ARegisteredName,
            // ⚠️ These four borrow their type from whatever is being configured — a default for a
            // text is a text, a minimum for a decimal is a decimal.
            self::DefaultValue, self::RangeMin,
            self::RangeMax, self::RangeStep            => SettingShape::LikeTheSubject,
            self::Icon                                 => SettingShape::Words,
        };
    }

    /**
     * Which engine keys have anything to say about this subject, whether or not one is set.
     *
     * ⚠️ **This is what lets a panel show what *applies* rather than only what is *stored*.** The
     * owner, looking at an `int` node whose chain was empty: *the settings that belong firmly to
     * the data type — min, max, step — should be shown as such.* A panel listing only what somebody
     * has written cannot say what could be written, and
     * [R33c](../../../docs/NewConcept/30-renderer.md#r33c--automatic-is-a-default-never-a-fact)
     * wants the opposite: *an automatic choice must be visible.*
     *
     * ⚠️ **Derived, never listed per type.** A key applies where a control can be drawn for it —
     * {@see typeFor()} answers that — or where it is a choice from a set. Writing out *which keys
     * an integer has* would be a table to maintain beside the truth, and the two would drift.
     *
     * @param  SimpleType|null $subject The simple type being configured, or null for anything else.
     * @param  bool            $isEdge  Whether the subject is a use site rather than a node.
     * @return list<self>
     */
    public static function applyingTo(?SimpleType $subject, bool $isEdge = false): array
    {
        $applying = [];

        foreach (self::cases() as $key) {
            // ⚠️ A node describes a thing, and a thing has no multiplicity (D-351).
            if ($key->isEdgeOnly() && ! $isEdge) {
                continue;
            }

            if ($key->typeFor($subject) !== null || $key->shape()->isAChoice()) {
                $applying[] = $key;
            }
        }

        return $applying;
    }

    /**
     * The type a control for this key should be drawn as, given what is being configured.
     *
     * ⚠️ **Null means *not a typed field*** — a choice from a set, which wants a chooser rather
     * than an input, and no chooser renderer is built yet. It also covers the honest case where the
     * subject has no type of its own to borrow: a `default` on a node that is not a simple data
     * type has no shape to be drawn in, and guessing `text` there would invite somebody to type a
     * reference as characters.
     *
     * @param SimpleType|null $subject What the setting is being written on, where that is a simple
     *                                 data type. Null for anything else.
     */
    public function typeFor(?SimpleType $subject): ?SimpleType
    {
        return match ($this->shape()) {
            SettingShape::Switch         => SimpleType::Bool,
            SettingShape::Whole          => SimpleType::Int,
            SettingShape::Words          => SimpleType::Text,
            SettingShape::LikeTheSubject => $subject,
            // A set to choose from, not a value to type.
            SettingShape::OneOfFour, SettingShape::ARegisteredName => null,
        };
    }
}
