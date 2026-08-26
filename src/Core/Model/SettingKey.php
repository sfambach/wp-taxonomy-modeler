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

    /**
     * Which validator checks what is entered — the third of the triple, and it was missing.
     *
     * ⚠️ **The owner named the three apart himself, and the reason is worth keeping:** *an `int`
     * converter is something different from an `int` validator or renderer. The **renderer** says how
     * it is shown, the **converter** says convert the output to binary, and the **validator** checks
     * whether the input is correct. So each has its own job.* Two of the three had keys and the third
     * did not — noticed on the settings panel, where he simply said *validator (display) setting is
     * missing*.
     *
     * ⚠️ **Several, eventually, and that is already decided.**
     * [D-158](../../../docs/NewConcept/90-decision-log.md) says a message is *per validator, not per
     * attribute* — *an attribute may carry range, format and uniqueness checks, three messages rather
     * than one* — so one key holding one name is the first step and not the final shape. *It is
     * honest as a first step because nothing can choose a second validator until any validator
     * exists.*
     *
     * ⚠️ *Like `converter`, it draws today as a **dead** control: none is built
     * ([D-219](../../../docs/NewConcept/90-decision-log.md) decided them), and R28–R32 wants a
     * disabled control rather than an empty box that looks fillable.*
     */
    case Validator = 'validator';

    case Icon = 'icon';

    /**
     * How much of the parent's reference unit this one is
     * ([D-274](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ **Named by the decision, not invented here:** *each unit carries its **factor** to the
     * parent's reference unit*, so inch → millimetre is a multiplication through the shared parent.
     * The parent **is** the dimension, because the tree is inheritance
     * ([D-041](../../../docs/NewConcept/90-decision-log.md)) — no new construct.
     */
    case Factor = 'factor';

    /**
     * What has to be added after the factor — the other half of D-274's rule.
     *
     * ⚠️ **Without it the commonest conversion of all falls outside the rule:** °C → °F is
     * `×1.8 + 32`. *And whatever is neither factor nor offset is a **converter**
     * ([D-219](../../../docs/NewConcept/90-decision-log.md)) — wire gauge to cross-section is a
     * table, not a calculation. The rule covers the linear case and hands the rest over honestly.*
     */
    case Offset = 'offset';

    /**
     * Whether a value given through this attribute is **kept**. Default **true**.
     *
     * ⚠️ **The owner brought it from object orientation and it is the piece that was missing:** *there
     * are attributes of an object, and there are ones that get persisted and ones that do not. A
     * multiplicator is not persistent — it counts only as an attribute.* **That justifies an
     * attribute where a record can never answer**, which is the thing four attempts had failed to
     * justify: a `node_label` type, a read-only-default trick, a reserved key on every node, and a
     * type marked derived. *All four were trying to say **this is not stored** in a place that could
     * not say it.*
     *
     * ```mermaid
     * flowchart LR
     *   T["a simple type · persistent = false"] --> A["every attribute using it"]
     *   A -->|"may override"| U["one use site"]
     * ```
     *
     * ⚠️ **Set on the type, inherited by the attribute, overridable there** — the owner's own
     * arrangement: *what we have on the attribute we have on the node too … first as a setting on
     * simple data types, the attribute takes it over and can override it.* That is the ordinary chain
     * ([D-015](../../../docs/NewConcept/90-decision-log.md), [D-032](../../../docs/NewConcept/90-decision-log.md))
     * and needs nothing new. **A type can therefore declare itself a calculation basis once** and
     * every use of it inherits that, instead of every author remembering it per edge.
     *
     * ⚠️ **Why *here* and not on {@see SimpleType}, which is where I first put the same idea and it
     * failed.** As a property of the **type** it made one of twelve types answer *no column at all*,
     * which is a hole in the type system. As a setting on the **subject** it is a decision per use,
     * resolved by the chain like every other — the same word, one level down, and that level is what
     * makes it work.
     *
     * ⚠️ **Choosing rather than bounding, and it is not a permission.**
     * [D-312](../../../docs/NewConcept/90-decision-log.md)'s narrowing rule governs what is
     * *allowed*; persistence governs what *happens*. *Flipping it is a storage change and not a
     * relaxation — the same shape as [D-134](../../../docs/NewConcept/90-decision-log.md)'s note that
     * changing a multiplicity from `1` to `1..*` becomes a migration.*
     *
     * ⚠️ **A model-level value still has a home**: [D-026](../../../docs/NewConcept/90-decision-log.md)
     * — *at model level there are no values, only defaults* — so a non-persistent attribute's value
     * is its `default`, which is what a default has always been.
     */
    case Persistent = 'persistent';


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
            self::Mandatory, self::Hide, self::ReadOnly,
            self::Persistent                           => SettingShape::Switch,
            self::Order                                => SettingShape::Whole,
            self::Factor, self::Offset                 => SettingShape::Exact,
            self::Multiplicity                         => SettingShape::OneOfFour,
            self::Renderer, self::Converter,
            self::Validator                            => SettingShape::ARegisteredName,
            // ⚠️ These four borrow their type from whatever is being configured — a default for a
            // text is a text, a minimum for a decimal is a decimal.
            self::DefaultValue, self::RangeMin,
            self::RangeMax, self::RangeStep            => SettingShape::LikeTheSubject,
            // ⚠️ **An icon is chosen from a set, not typed** (D-390): the installation offers a
            // list and a person picks one, so a text box here would ask somebody to know a Dashicon
            // key by heart. *Which icons exist is a boundary fact and arrives with the options.*
            self::Icon                                 => SettingShape::ARegisteredName,
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
            SettingShape::Exact          => SimpleType::Decimal,
            SettingShape::Words          => SimpleType::Text,
            SettingShape::LikeTheSubject => $subject,
            // A set to choose from, not a value to type.
            SettingShape::OneOfFour, SettingShape::ARegisteredName => null,
        };
    }
}
