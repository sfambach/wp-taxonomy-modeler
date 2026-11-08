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
            '«%s» is a composition, and a composed part is a record of its own — not built yet.',
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
    /**
     * Ein Feld mit Benutzersätzen soll eine Einstellung werden — erst der Haken, dann der Wechsel (D-699).
     */
    public static function kindChangeNeedsConfirmation(string $attribute, int $records, int $values): self
    {
        return new self(sprintf(
            $records === 1
                ? '«%1$s» keeps values in %2$d entry (%3$d values). Making it a setting moves that entry to the shadow — tick the confirmation in the row and save again.'
                : '«%1$s» keeps values in %2$d entries (%3$d values). Making it a setting moves those entries to the shadow — tick the confirmation in the row and save again.',
            $attribute,
            $records,
            $values
        ));
    }

    /**
     * Ein Knoten mit Einträgen soll unter `Primitives` — erst die Bestätigung, dann der Umzug (D-701).
     */
    public static function moveNeedsConfirmation(string $node, string $target, int $records, int $values): self
    {
        return new self(sprintf(
            $records === 1
                ? '«%1$s» carries %3$d entry (%4$d values) that nothing refers to. Under «%2$s» there is no place for it — confirm below to move the node and send that entry to the shadow.'
                : '«%1$s» carries %3$d entries (%4$d values) that nothing refers to. Under «%2$s» there is no place for them — confirm below to move the node and send those entries to the shadow.',
            $node,
            $target,
            $records,
            $values
        ));
    }

    /** ⚠️ *«unique (eindeutig)» an der Kante (D-735): derselbe Wert steht schon in einem anderen Satz.* */
    public static function thatValueIsTaken(string $attribute, string $value, int $recordId): self
    {
        return new self(sprintf('«%s» must be unique — «%s» is already held by record #%d.', $attribute, $value, $recordId));
    }

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
     * ⚠️ *A part exists only where the edge is a composition
     * ([D-938](../../../docs/NewConcept/90-decision-log.md), replacing the branch of D-232). Anywhere else the value belongs **in**
     * the holder's record, and a part would be a second home for the same fact.*
     */
    public static function thatIsNotAComposedPart(string $attribute): self
    {
        return new self(sprintf(
            '«%s» is not a composition, so it holds no part of its own.',
            $attribute
        ));
    }

    /** Ein Satz wandert nur zum Vater oder in ein direktes Kind (D-756). */
    public static function notANeighbourOf(string $target, string $node): self
    {
        return new self(sprintf('«%s» is neither the parent nor a child of «%s».', $target, $node));
    }

    /** Ein Satz wandert nur innerhalb seines Baums (D-1073). */
    public static function notInTheTreeOf(string $target, string $node): self
    {
        return new self(sprintf('«%s» is not in the tree of «%s».', $target, $node));
    }

    /** Ein Umzug liesse Verweise fremder Sätze ausserhalb ihrer Auswahl zeigen (D-1073). */
    public static function wouldStrandReferences(int $recordId, string $target, string $fields): self
    {
        return new self(sprintf('Record %d cannot move to «%s»: it is still chosen in %s, which cannot choose it there.', $recordId, $target, $fields));
    }

    /** Die Validatoren haben etwas auszusetzen — nichts wird gespeichert (D-760). */
    public static function refusedByValidators(string $liste): self
    {
        return new self(sprintf('Not saved — %s.', $liste));
    }

    /**
     * ⚠️ *[D-1099](../../../docs/NewConcept/90-decision-log.md), sein Wort «f3 verweigern»: ein Kopf, auf dessen Teil noch ein
     * fremder Satz zeigt, wird nicht gelöscht — und die Meldung nennt, wer zeigt.*
     *
     * @param list<string> $wer Je Verweis «part #12 ← record #40 («Kauf»)».
     */
    public static function partsStillReferenced(int $recordId, array $wer): self
    {
        return new self(sprintf(
            'Record %d cannot be deleted: its parts are still referenced — %s. Remove those references first.',
            $recordId,
            implode('; ', $wer)
        ));
    }

    /** Ein Teil wechselt den Kopf nur innerhalb desselben Feldes (D-1098). */
    public static function notAPartOnThatField(int $partId, string $attribute): self
    {
        return new self(sprintf('Record %d is not a part held through «%s».', $partId, $attribute));
    }

    /** Ein Feld mit höchstens einem Wert nimmt keinen zweiten Teil an (D-1098). */
    public static function fieldAlreadyHoldsItsPart(string $attribute, int $recordId): self
    {
        return new self(sprintf('«%s» of record %d takes one part and already holds it.', $attribute, $recordId));
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
    public static function thatSettingHasNoRelationYet(string $key): self
    {
        return new self(sprintf('The setting «%s» has no relation written down yet, so nothing can be stored at it.', $key));
    }

    /** A variant holds only its «Je Variante» fields; every other one is its parent's (D-1208). */
    public static function thatFieldIsTheParents(string $attribute, int $parent): self
    {
        return new self(sprintf('«%s» is read from the parent #%d — change it there, or mark the field «Je Variante».', $attribute, $parent));
    }

    public static function thatCannotBeAVariant(int $record, int $parent, \Taxmod\Core\Model\VariantComplaint $why): self
    {
        return new self(sprintf('#%d cannot be a variant of #%d: %s', $record, $parent, match ($why) {
            \Taxmod\Core\Model\VariantComplaint::OfItself        => 'a record is no variant of itself.',
            \Taxmod\Core\Model\VariantComplaint::ParentIsVariant => 'the parent is a variant itself.',
            \Taxmod\Core\Model\VariantComplaint::HasVariants     => 'it has variants of its own.',
        }));
    }

    public static function notAFieldOfThisModel(int $relationId, string $model): self
    {
        return new self(sprintf('Field %d does not belong to «%s» or anything it inherits from.', $relationId, $model));
    }

    /**
     * ⚠️ *Eine Verwendungsstelle wird über ihre **Id** angesprochen, und eine Id, die auf nichts zeigt,
     * ist Eingabe und kein Zustand — sie wird gemeldet und nicht als «nichts zu tun» geschluckt.*
     */
    public static function noSuchUseSite(int $relationId): self
    {
        return new self(sprintf('There is no use site %d.', $relationId));
    }
}
