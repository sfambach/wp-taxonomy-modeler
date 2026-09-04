<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * A value that has nowhere to go — or nowhere it can go **yet**.
 *
 * ⚠️ **The `yet` matters.** One of these is a rule (a data type has no instances, D-183) and one
 * is unfinished work (a composed part needs a record of its own). Refusing both is right;
 * conflating them in the message would not be.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class NotYetStorable extends DomainError
{
    /** A rule: only branches with instances have records (D-183). */
    public static function thatBranchHasNoRecords(string $name): self
    {
        return new self(sprintf(
            '«%s» has no records of its own — only things under Model and Compositions do.',
            $name
        ));
    }

    /**
     * The attribute points at something that is not a simple data type, so its value has no
     * characters of its own to be typed in.
     *
     * ⚠️ **Refused rather than stored as text.** A reference wants the reference renderer
     * ([D-105](../../../docs/NewConcept/90-decision-log.md)) and a composed part a record of its
     * own; keeping whatever was typed would look right until somebody tried to follow it.
     */
    public static function thatFieldHasNoTypeYet(string $attribute): self
    {
        return new self(sprintf(
            '«%s» does not point at a simple data type, so there is nothing to type in yet.',
            $attribute
        ));
    }

    /** Unfinished work, and said so plainly rather than stored in the wrong place. */
    public static function compositionsNeedTheirOwnRecords(string $attribute): self
    {
        return new self(sprintf(
            '«%s» points into Compositions, and a composed part is a record of its own — not built yet.',
            $attribute
        ));
    }

    /**
     * ⚠️ **Not a *yet*, unlike its neighbours here.** The others say *nobody has built this*; this
     * one says *the model declared that nothing is kept* ([D-378](../../../docs/NewConcept/90-decision-log.md)),
     * which is an answer and not a gap. It shares the class because the caller's question is the
     * same — *can this value be stored* — and the honest reply is no either way.
     */
    /**
     * ⚠️ **«Setze den Wert» ist keine Frage, die ein Feld mit mehreren Werten beantworten kann.**
     * *Seit [D-530](../../../docs/NewConcept/90-decision-log.md) stehen mehrere Werte als mehrere
     * Zeilen nebeneinander; früher verhinderte der eindeutige Schlüssel den Fall. **Verweigert statt
     * geraten:** eine der Zeilen zu treffen wäre in der Hälfte der Fälle die falsche, und man sähe es
     * erst an den Daten.*
     */
    public static function thatFieldHasSeveralValues(string $attribute, int $anzahl): self
    {
        return new self(sprintf(
            '«%s» holds %d values — say which one, or append instead of setting.',
            $attribute,
            $anzahl
        ));
    }

    public static function thatFieldKeepsNothing(string $attribute): self
    {
        return new self(sprintf(
            // ⚠️ *Wortlaut nachgezogen am 2026-09-01: «not persistent» war das Vokabular von
            // [D-538]s Vorgänger. Die Kante ist eine **Einstellung** — das ist der Grund, und
            // `keepsValues()` fragt genau das.*
            '«%s» is a setting, not a field — its value lives as a default and is read, never written.',
            $attribute
        ));
    }

    /**
     * ⚠️ *A part exists only where the branch says the value has records of its own
     * ([D-232](../../../docs/NewConcept/90-decision-log.md)). Anywhere else the value belongs **in**
     * the holder's record, and a part would be a second home for the same fact.*
     */
    public static function thatIsNotAComposedPart(string $attribute): self
    {
        return new self(sprintf(
            '«%s» does not point into Compositions, so its value lives in the record itself.',
            $attribute
        ));
    }

    public static function noSuchRecord(int $id): self
    {
        return new self(sprintf('There is no record %d.', $id));
    }

    /**
     * ⚠️ **Kein stilles Nichts** ([D-543](../../../docs/NewConcept/90-decision-log.md)). *Wenn die Saat
     * die Id der Einstellungskante noch nicht aufgeschrieben hat, ist Schreiben unmöglich — und der
     * Vorgänger dieser Zeile, ein `return` ohne Wort, ist genau der Grund, warum ein Renderer-Ausfall
     * einen ganzen Tag unsichtbar bleiben konnte.*
     */
    public static function thatSettingHasNoEdgeYet(string $key): self
    {
        return new self(sprintf('The setting «%s» has no edge written down yet, so nothing can be stored at it.', $key));
    }

    public static function notAFieldOfThisModel(int $edgeId, string $model): self
    {
        return new self(sprintf('Field %d does not belong to «%s» or anything it inherits from.', $edgeId, $model));
    }

    /**
     * ⚠️ *Eine Verwendungsstelle wird über ihre **Id** angesprochen, und eine Id, die auf nichts zeigt,
     * ist Eingabe und kein Zustand — sie wird gemeldet und nicht als «nichts zu tun» geschluckt.*
     */
    public static function noSuchUseSite(int $edgeId): self
    {
        return new self(sprintf('There is no use site %d.', $edgeId));
    }
}
