<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

use Taxmod\Core\Exception\NotAValueOfThatType;

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
        ?ReferenceSpace $referenceSpace = null,
    ) {
        // ⚠️ *Die Zusicherung wird hier hergestellt und nicht von den Aufrufern verlangt: ein Verweis
        // ohne Raumangabe ist genau der mehrdeutige Zustand, den TASK-005 beseitigt. Ein Verweis ohne
        // Angabe meint einen **Knoten** — das ist, was jeder Aufrufer ausser {@see DataEntry} meint.
        $this->referenceSpace = $reference === null ? null : ($referenceSpace ?? ReferenceSpace::Node);
    }

    /** Der Raum, in den {@see $reference} zeigt — **nie `null`, solange ein Verweis da ist**. */
    public readonly ?ReferenceSpace $referenceSpace;


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
        ?ReferenceSpace $referenceSpace = null,
    ): self {
        return new self($int, $decimal, $text, $date, $reference, $referenceSpace);
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
        return new self(reference: $nodeId, referenceSpace: ReferenceSpace::Node);
    }

    /**
     * Ein Verweis auf eine **Ausprägung** statt auf einen Knoten — der eingebettete Teil
     * ({@see \Taxmod\Core\Service\DataEntry::createPart()}).
     */
    public static function ofRecordReference(int $recordId): self
    {
        return new self(reference: $recordId, referenceSpace: ReferenceSpace::Record);
    }

    /** Ein Verweis auf ein Feld (eine Kante) — der Attributtyp aus D-752. */
    public static function ofRelationReference(int $relationId): self
    {
        return new self(reference: $relationId, referenceSpace: ReferenceSpace::Relation);
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

    /**
     * Ordnet diesen Wert gegen einen anderen — `-1`, `0`, `1`, oder `null`, wenn sie nicht vergleichbar
     * sind.
     *
     * ⚠️ **Hier, weil `equals()` hier steht.** *«Wie zwei Werte sich vergleichen» ist **eine** Tatsache,
     * und ein zweiter Vergleich in einem Validator oder in einer Suche wäre die zweite Heimat dafür — der
     * Fehler, der dieses Projekt am meisten gekostet hat.*
     *
     * ⚠️ **Eine Dezimalzahl wird nie zu `float`** ([D-057](../../../docs/NewConcept/90-decision-log.md)):
     * *`0.1 + 0.2` ist als Gleitkommazahl nicht `0.3`, und `(float) '1.10' > (float) '1.9'` ist zwar
     * richtig, aber der Weg dahin ist es nicht — bei genug Stellen kippt er. Verglichen wird ziffernweise:
     * erst der ganze Teil als Zahl, dann der Bruchteil, auf gleiche Länge aufgefüllt.*
     *
     * ⚠️ **Ein Datum vergleicht sich als Zeichenkette, und das ist kein Trick**: *die Form
     * `YYYY-MM-DD HH:MM:SS` ist genau deshalb so gewählt, dass die alphabetische Ordnung die zeitliche
     * ist.*
     *
     * ⚠️ *`null` heisst «nicht vergleichbar» und ist **nicht** `0`. Ein Text gegen eine Zahl, oder
     * irgendetwas gegen «nichts» — beides ist keine Ordnung, und `0` zurückzugeben hiesse «gleich», was
     * eine Grenze stillschweigend erfüllen würde.*
     */
    public function comparedTo(self $other): ?int
    {
        if ($this->isNothing() || $other->isNothing()) {
            return null;
        }

        if ($this->int !== null && $other->int !== null) {
            return $this->int <=> $other->int;
        }

        $meine  = $this->numericText();
        $andere = $other->numericText();

        if ($meine !== null && $andere !== null) {
            return self::compareDecimals($meine, $andere);
        }

        if ($this->date !== null && $other->date !== null) {
            return strcmp($this->date, $other->date) <=> 0;
        }

        if ($this->text !== null && $other->text !== null) {
            return strcmp($this->text, $other->text) <=> 0;
        }

        return null;
    }

    /** Die Zahl als Zeichen, wenn dieser Wert eine ist — `int` und `decimal` mischen sich hier. */
    private function numericText(): ?string
    {
        if ($this->decimal !== null) {
            return $this->decimal;
        }

        return $this->int === null ? null : (string) $this->int;
    }

    /** ⚠️ *Ziffernweise, ohne `float` — siehe {@see self::comparedTo()}.* */
    private static function compareDecimals(string $a, string $b): int
    {
        $negativA = str_starts_with($a, '-');
        $negativB = str_starts_with($b, '-');

        if ($negativA !== $negativB) {
            return $negativA ? -1 : 1;
        }

        [$ganzA, $bruchA] = self::teile($a);
        [$ganzB, $bruchB] = self::teile($b);

        $laenge = max(strlen($bruchA), strlen($bruchB));
        $bruchA = str_pad($bruchA, $laenge, '0');
        $bruchB = str_pad($bruchB, $laenge, '0');

        // ⚠️ *Der ganze Teil kann länger sein als ein `int` fasst, also auch er als Zeichen: erst die
        // Länge (nach dem Streichen führender Nullen), dann Ziffer für Ziffer.*
        $ordnung = self::compareDigits($ganzA, $ganzB) ?: strcmp($bruchA, $bruchB) <=> 0;

        return $negativA ? -$ordnung : $ordnung;
    }

    /** @return array{0: string, 1: string} Ganzer Teil ohne Vorzeichen und führende Nullen, Bruchteil. */
    private static function teile(string $zahl): array
    {
        $ohneVorzeichen = ltrim($zahl, '+-');
        $punkt          = strpos($ohneVorzeichen, '.');

        $ganz  = $punkt === false ? $ohneVorzeichen : substr($ohneVorzeichen, 0, $punkt);
        $bruch = $punkt === false ? '' : substr($ohneVorzeichen, $punkt + 1);

        $ganz = ltrim($ganz, '0');

        return [$ganz === '' ? '0' : $ganz, $bruch];
    }

    private static function compareDigits(string $a, string $b): int
    {
        if (strlen($a) !== strlen($b)) {
            return strlen($a) <=> strlen($b);
        }

        return strcmp($a, $b) <=> 0;
    }

    public function equals(self $other): bool
    {
        return $this->int === $other->int
            && $this->decimal === $other->decimal
            && $this->text === $other->text
            && $this->date === $other->date
            && $this->reference === $other->reference;
    }

    /**
     * For a message, a log line or a diagnostic — **never for storage, comparison or a surface**.
     *
     * ⚠️ **The last of those three was missing and it cost a visible bug.** A reference used to
     * come back as `→ 285`, two renderers called this to draw a value, and a **bare id reached the
     * screen** — which [D-363](../../../docs/NewConcept/90-decision-log.md) forbids in as many
     * words: *a bare number is the sort of thing that gets copied into a spreadsheet as if it meant
     * something.* It was also a domain object deciding what a thing looks like, which the code
     * standard forbids outright.
     *
     * ⚠️ **So a reference now describes itself as a reference and does not spell out the id.** *A
     * reference's appearance is its target's label, and only a renderer that has been handed that
     * label can draw it ([D-105](../../../docs/NewConcept/90-decision-log.md),
     * [D-159](../../../docs/NewConcept/90-decision-log.md)).*
     */
    public function describe(): string
    {
        return match (true) {
            $this->isNothing()             => '(nothing)',
            $this->int !== null            => (string) $this->int,
            $this->decimal !== null        => $this->decimal,
            $this->date !== null           => $this->date,
            $this->reference !== null      => '(a reference)',
            default                        => (string) $this->text,
        };
    }

    /** Whether this value points at a node, which only a reference renderer may draw. */
    public function isAReference(): bool
    {
        return $this->reference !== null;
    }

    /**
     * Which of the five columns carries this value — the `type` a journal entry needs.
     *
     * ⚠️ **This is the half {@see describe()} cannot give**, and it is why a journal entry was not
     * replayable ([D-427](../../../docs/NewConcept/90-decision-log.md)): `describe()` is prose —
     * `10` says nothing about whether ten is an integer, an exact decimal or the text «10», and a
     * reference reads as «(a reference)» with the id deliberately gone
     * ([D-400](../../../docs/NewConcept/90-decision-log.md),
     * [D-363](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *A boolean answers `int`, and that is not a gap: `0` and `1` in the integer column **is**
     * how a boolean is stored ([D-315](../../../docs/NewConcept/90-decision-log.md)), so
     * `ofBool(true)` and `ofInt(1)` are the same value and must come back as the same value.*
     */
    public function typeName(): string
    {
        return match (true) {
            $this->isNothing()        => 'nothing',
            $this->int !== null       => 'int',
            $this->decimal !== null   => 'decimal',
            $this->date !== null      => 'date',
            // ⚠️ *Zwei Namen statt einem, seit TASK-005: ein Rückspielen muss den **Raum**
            // mitbekommen, sonst zeigt der wiederhergestellte Verweis auf einen Knoten mit der
            // Nummer eines Datensatzes. Ältere Journalzeilen sagen nur «reference» und werden
            // als Knotenverweis gelesen — was sie bis hierher auch bedeutet haben.*
            $this->referenceSpace === ReferenceSpace::Record => 'record_reference',
            $this->referenceSpace === ReferenceSpace::Relation => 'relation_reference',
            $this->reference !== null => 'reference',
            default                   => 'text',
        };
    }

    /**
     * The value as the characters that were stored — **data, not prose**.
     *
     * ⚠️ **A reference spells out its id here, which {@see describe()} refuses to do.** *The two are
     * for different readers: `describe()` feeds a message or a screen, where a bare id is forbidden
     * outright ([D-363](../../../docs/NewConcept/90-decision-log.md)) because a number copied out of
     * a page looks like it means something. A journal state feeds a **replay**, and a replay cannot
     * re-point a reference at «(a reference)».*
     *
     * ⚠️ *So anything that ever shows a journal state to a person renders the reference through its
     * target's label, exactly as every other surface does — the column being honest is not a licence
     * to print it.*
     */
    public function rawValue(): string
    {
        return match (true) {
            $this->isNothing()        => '',
            $this->int !== null       => (string) $this->int,
            $this->decimal !== null   => $this->decimal,
            $this->date !== null      => $this->date,
            $this->reference !== null => (string) $this->reference,
            default                   => (string) $this->text,
        };
    }

    /**
     * Rebuild from {@see typeName()} and {@see rawValue()} — the round trip a replay walks back.
     *
     * ⚠️ *Refuses an unknown name rather than falling back to text: a type nobody wrote is a journal
     * row from a future version, and reading it as a string would put the wrong thing in a column
     * without anybody noticing.*
     */
    public static function ofTypeName(string $type, string $rawValue): self
    {
        return match ($type) {
            'nothing'   => self::nothing(),
            'int'       => self::ofInt((int) $rawValue),
            'decimal'   => self::ofDecimal($rawValue),
            'date'      => self::ofDate($rawValue),
            'reference'        => self::ofReference((int) $rawValue),
            'record_reference' => self::ofRecordReference((int) $rawValue),
            'relation_reference' => self::ofRelationReference((int) $rawValue),
            'text'      => self::ofText($rawValue),
            default     => throw NotAValueOfThatType::submitted($rawValue, $type),
        };
    }
}
