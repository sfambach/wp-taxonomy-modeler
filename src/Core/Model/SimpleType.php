<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

use Taxmod\Core\Model\Type\SpecialisedType;
use Taxmod\Core\Model\Type\SpecialisedTypes;

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
    // ⚠️ *Der Weg vom erklärenden Vater bis zum Knoten, gerechnet beim Zeichnen (D-751).*
    case Path = 'path';
    // ⚠️ *Ein Sprung zu einem anderen Knoten, gefiltert nach diesem Satz — gerechnet beim Zeichnen, nie gespeichert (D-769).*
    case Jump = 'jump';
    // ⚠️ *Andere Felder desselben Satzes als ein Text, gespeichert und bei jeder Änderung neu geschrieben (D-885).*
    case Summary = 'summary';

    /** Eine Datei oder ein Link — gespeichert wird die Adresse ([D-793](../../../docs/NewConcept/90-decision-log.md): «it is a media type, both is the right answer»). */
    case Media = 'media';

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
        return $this->specialised()->column();
    }

    /**
     * Die Klasse, die diesen Fall ausmacht — **und die alles beantwortet, was unten steht**.
     *
     * ⚠️ **Der Fall ist die Adresse, die Klasse ist die Wahrheit** ([D-484](../../../docs/NewConcept/90-decision-log.md)).
     * *Bis zum 2026-09-05 stand jede dieser Auskünfte hier als `match` über elf Fälle; sie stehen
     * jetzt je einmal in {@see \Taxmod\Core\Model\Type\SpecialisedType}s Kind. Was hier bleibt, sind
     * die Signaturen, damit kein Aufrufer sich ändern muss — **eine Weiterleitung und keine zweite
     * Heimat**.*
     */
    public function specialised(): SpecialisedType
    {
        return SpecialisedTypes::for($this);
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
        return $this->specialised()->humanName();
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
     * ⚠️ **The second job is gone** ([D-510](../../../docs/NewConcept/90-decision-log.md)): the
     * binding is the node's **id**, written down by the seed. *So this returns the word a person
     * reads and nothing depends on it any more — which is what a name should have been all along.*
     *
     * ⚠️ *Two choices in here are mine and are flagged rather than smuggled: `Decimal` rather than
     * {@see self::humanName()}'s **double** — he asked for consistent spelling, not a different word
     * — and `Boolean` rather than **yes or no**, which is a phrase for a heading and not a name a
     * person types.*
     */
    public function nodeName(): string
    {
        return $this->specialised()->nodeName();
    }

    /**
     * The type a node of this name stands for — **the Notnagel, and no longer the link.**
     *
     * ⚠️ **The binding is the node's id** ([D-510](../../../docs/NewConcept/90-decision-log.md)),
     * written down by the seed and read through {@see \Taxmod\Core\Repository\TypeNodes}. *This method
     * used to say «its name, which is the only link there is», and that sentence is what the decision
     * removed: a check looked for a node called `int` — it is called `Integer` — **so it never ran and
     * preserved a contradiction for three days.** And [D-022](../../../docs/NewConcept/90-decision-log.md)
     * says node names are deliberately not unique, so a name could never have been a key.*
     *
     * ⚠️ **What it is still for: the installation that has no ids written down yet.** *An upgrade must
     * not be a loss, so a lookup that finds nothing falls back to here **once** and then writes the id
     * down. Nothing in the drawing path reaches this method any more.*
     *
     * ⚠️ **Both spellings answer, and that is not indecision.** A tree that has not been migrated yet
     * still holds a node called `int`, and the fallback must recognise it. *The old value is accepted
     * for as long as an installation can still carry it; the new name is what gets written.*
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
        return $this->specialised()->pattern();
    }

    /** Which on-screen keyboard the control should ask for. */
    public function inputMode(): ?string
    {
        return $this->specialised()->inputMode();
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

        // ⚠️ *Und ab hier antwortet der Typ selbst* ([D-484](../../../docs/NewConcept/90-decision-log.md)).
        // Text, E-Mail, Farbe, Fassung und ein fremder Benutzerschlüssel werden abgelegt, wie sie
        // kamen — ob eine Adresse eine ist, ist die Frage eines **Validators** (D-319).
        return $this->specialised()->valueFrom($characters);
    }
}
