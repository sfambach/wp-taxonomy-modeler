<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

use Taxmod\Core\Exception\NotAValueOfThatType;

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

    /**
     * The word a person reads for this type.
     *
     * ⚠️ **`int` reads *integer* and `decimal` reads *double***, which is how the owner named them
     * when he asked for the grouping: *settings for `int` category integer, settings for `double`
     * category double.* The stored names are the short machine ones and stay that way; this is only
     * what a heading shows.
     *
     * ⚠️ *English here and translated at the boundary, because the core cannot make a word (`AR-2`,
     * [OQ-087](../../../docs/NewConcept/91-open-questions.md)). What it can do is say **which** word,
     * so a twelfth type gets a heading without anybody remembering to add one.*
     */
    public function humanName(): string
    {
        return match ($this) {
            self::Int      => 'integer',
            self::Decimal  => 'double',
            self::NodeRef  => 'node reference',
            self::UserRef  => 'user reference',
            self::DateTime => 'date and time',
            self::Char     => 'character',
            self::Bool     => 'yes or no',
            default        => $this->value,
        };
    }

    /**
     * The name the **node** carries in the tree — spelled out, not abbreviated.
     *
     * ⚠️ **The owner: *the data type `int` is shown as `int`, `decimal` as `decimal` — unify that,
     * for `int` = `Integer`.*** He is pointing at an inconsistency that `CD-9` already forbids in
     * code and that the tree had inherited: *no abbreviations that need a lookup.* `decimal` reads as
     * a word, `int` does not, and both sit in the same list.
     *
     * ⚠️ **This is a **name**, not a label, and [D-369](../../../docs/NewConcept/90-decision-log.md)
     * is why.** *The modelling tree shows a node's own name … «there I would take the node name».* So
     * making the tree read `Integer` means the node **is** called `Integer` — a label would not show
     * there at all.
     *
     * ⚠️ **And that is why `value` stays what it is.** The node's name was doing two jobs: what a
     * person reads **and** how {@see self::fromNodeName()} recognises the type. *Renaming the enum's
     * values instead would have meant a sweep of some seventy string literals across scaffolds,
     * checks and tests — for a change that is about a word on a screen.* **The identifier stays
     * short and machine-shaped; the name becomes the word.**
     *
     * ⚠️ *Two choices in here are mine and are flagged rather than smuggled: `Decimal` rather than
     * {@see self::humanName()}'s **double** — he asked for consistent spelling, not a different word
     * — and `Boolean` rather than **yes or no**, which is a phrase for a heading and not a name a
     * person types.*
     */
    public function nodeName(): string
    {
        return match ($this) {
            self::Int      => 'Integer',
            self::Decimal  => 'Decimal',
            self::Text     => 'Text',
            self::Char     => 'Character',
            self::Bool     => 'Boolean',
            self::Email    => 'Email',
            self::DateTime => 'Date and time',
            self::Color    => 'Color',
            self::Version  => 'Version',
            self::NodeRef  => 'Node reference',
            self::UserRef  => 'User reference',
        };
    }

    /**
     * The type a node of this name stands for — by its **name**, which is the only link there is.
     *
     * ⚠️ **Both spellings answer, and that is not indecision.** A tree that has not been migrated yet
     * still holds a node called `int`, and a renderer asking *what type is this* must not go blank in
     * between. *The old value is accepted for as long as an installation can still carry it; the new
     * name is what gets written.*
     */
    public static function fromNodeName(string $name): ?self
    {
        foreach (self::cases() as $type) {
            if ($name === $type->nodeName() || $name === $type->value) {
                return $type;
            }
        }

        return null;
    }

    /** @return list<string> The node names, in the order they are seeded. */
    public static function names(): array
    {
        return array_map(static fn (self $type): string => $type->nodeName(), self::cases());
    }

    /**
     * The shape this type's characters must have — **one fact in one place**.
     *
     * ⚠️ **The browser and the core check the same rule, and they must not each carry their own
     * copy of it.** A control that accepted what the core then refused would be the trap
     * [R28](../../../docs/NewConcept/30-renderer.md#r28r32--the-rule-complete) exists to prevent —
     * *a control offers only real choices* — and two regexes drifting apart is how that trap gets
     * built by accident. So {@see valueFrom()} anchors this pattern, and a renderer puts the very
     * same string in the control's `pattern` attribute.
     *
     * Null means *any characters at all*: a text, an address, a colour name. Validity beyond the
     * shape is a **validator's** question (D-319), not this one's.
     *
     * ⚠️ **This is the *storage* shape, and it is the *accepted* shape only while no converter is
     * in effect** (D-356). The owner named the case that proves it: letters in a numeric field can
     * be exactly what is wanted — type `4k7` and mean `4700`
     * ([R35a](../../../docs/NewConcept/30-renderer.md#r35a--notation-is-not-structure)) — because
     * a converter *removes what cannot have been meant*
     * ([R36a](../../../docs/NewConcept/30-renderer.md#r36a--the-converter-removes-what-cannot-have-been-meant-the-validator-asks-about-the-rest)).
     * **So when converters arrive, the pattern comes from the converter in effect and this becomes
     * the fallback**, or this method turns into a wall against the very notations the concept
     * promises. *What it must never do is loosen for the case it cannot express: `1kg` is a **unit
     * value**, a composed type of number and unit (D-220) — not an integer with text after it, and
     * not something a pure `int` field should ever swallow.*
     */
    public function pattern(): ?string
    {
        return match ($this) {
            self::Int     => '-?\d+',
            self::Decimal => '-?\d+(\.\d+)?',
            default       => null,
        };
    }

    /** Which on-screen keyboard the control should ask for. */
    public function inputMode(): ?string
    {
        return match ($this) {
            self::Int     => 'numeric',
            self::Decimal => 'decimal',
            default       => null,
        };
    }

    /**
     * Turn submitted characters into a value of this type.
     *
     * ⚠️ **This is not the converter, and the difference matters.**
     * [R36a](../../../docs/NewConcept/30-renderer.md#r36a--the-converter-removes-what-cannot-have-been-meant-the-validator-asks-about-the-rest)
     * gives a converter the job of *removing what cannot have been meant* — `4k7` into `4700`, a
     * thousands separator away, a locale's comma into a point. **None of that happens here.** This
     * is the type's own plain form, the one its control submits, and anything else is
     * {@see \Taxmod\Core\Exception\NotAValueOfThatType} rather than a guess. When converters
     * arrive they sit **in front** of this and nothing here changes.
     *
     * ⚠️ **Nothing coerces.** `(int) 'abc'` is `0`, and a zero that arrived that way can never
     * again be told apart from one somebody meant (D-071).
     *
     * @throws \Taxmod\Core\Exception\NotAValueOfThatType
     */
    public function valueFrom(string $characters): TypedValue
    {
        $characters = trim($characters);

        if ($characters === '') {
            return TypedValue::nothing();
        }

        return match ($this) {
            self::Int     => $this->integer($characters),
            self::Decimal => $this->exactDecimal($characters),
            self::Bool    => $this->boolean($characters),
            self::DateTime => TypedValue::ofDate($this->timestamp($characters)),
            self::NodeRef => $this->nodeReference($characters),
            self::Char    => $this->oneCharacter($characters),
            // ⚠️ Text, email, colour, version and a foreign user key are stored as given. Whether
            // an address is one, or a version well-formed, is a **validator's** question (D-319) —
            // and a renderer that never writes has no business tidying it either (D-159).
            default       => TypedValue::ofText($characters),
        };
    }

    private function integer(string $characters): TypedValue
    {
        $this->mustMatchItsShape($characters);

        return TypedValue::ofInt((int) $characters);
    }

    /** Kept as the characters it arrived as — a decimal never becomes a float (D-057). */
    private function exactDecimal(string $characters): TypedValue
    {
        $this->mustMatchItsShape($characters);

        return TypedValue::ofDecimal($characters);
    }

    /**
     * ⚠️ **The same pattern the control carries**, anchored. Writing the rule out a second time
     * here is how a control and its core come to disagree, and the disagreement only shows up as
     * *the form refuses what the field allowed*.
     */
    private function mustMatchItsShape(string $characters): void
    {
        $pattern = $this->pattern();

        if ($pattern !== null && preg_match('/^' . $pattern . '$/', $characters) !== 1) {
            throw NotAValueOfThatType::submitted($characters, $this->value);
        }
    }

    private function boolean(string $characters): TypedValue
    {
        return match (strtolower($characters)) {
            '1', 'true', 'on', 'yes'  => TypedValue::ofBool(true),
            '0', 'false', 'off', 'no' => TypedValue::ofBool(false),
            default                   => throw NotAValueOfThatType::submitted($characters, $this->value),
        };
    }

    private function nodeReference(string $characters): TypedValue
    {
        if (preg_match('/^\d+$/', $characters) !== 1) {
            throw NotAValueOfThatType::submitted($characters, $this->value);
        }

        return TypedValue::ofReference((int) $characters);
    }

    /**
     * ⚠️ **Counted in characters, not bytes.** `mb_strlen` is why `ä` is one `char` and not two —
     * a `char` has a numeric identity behind it (D-329), and that identity is a code point.
     */
    private function oneCharacter(string $characters): TypedValue
    {
        if (mb_strlen($characters, 'UTF-8') !== 1) {
            throw NotAValueOfThatType::submitted($characters, $this->value);
        }

        return TypedValue::ofText($characters);
    }

    /**
     * The three shapes a date control submits, normalised to what the column holds.
     *
     * ⚠️ **A time with no date is stored against the epoch, and that is a compromise, not a
     * design.** The column is a `datetime` (D-291 gives date, time and both to one type), so a
     * time of day has nowhere to sit without a date beside it. The epoch is used because it is
     * recognisable and because the precision setting is what says the date part carries no
     * meaning — but a stored fact nobody meant is exactly what this model tries not to have. See
     * [OQ-088](../../../docs/NewConcept/91-open-questions.md).
     */
    private function timestamp(string $characters): string
    {
        $characters = str_replace('T', ' ', $characters);

        return match (true) {
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $characters) === 1
                => $characters . ' 00:00:00',
            preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $characters) === 1
                => self::TIME_WITHOUT_A_DATE . ' ' . $this->withSeconds($characters),
            preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $characters) === 1
                => substr($characters, 0, 10) . ' ' . $this->withSeconds(substr($characters, 11)),
            default
                => throw NotAValueOfThatType::submitted($characters, $this->value),
        };
    }

    private function withSeconds(string $time): string
    {
        return strlen($time) === 5 ? $time . ':00' : $time;
    }

    /** The date a time-of-day is parked against when it has none of its own. */
    public const TIME_WITHOUT_A_DATE = '1970-01-01';
}
