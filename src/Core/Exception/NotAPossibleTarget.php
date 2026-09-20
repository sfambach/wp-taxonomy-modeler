<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * A node that cannot stand at the far end of an attribute.
 *
 * ⚠️ **Seit [D-890](../../../docs/NewConcept/90-decision-log.md) verlangt ein Ziel keinen benannten Ast mehr** — *sein Wort: «bitte keine
 * benannten äste», und die Art nennt ohnehin der Benutzer ([D-618](../../../docs/NewConcept/90-decision-log.md)). Übrig sind die zwei
 * Verbote mit Grund: ein **Rahmenknoten** steht für einen Ort und nicht für ein Ding (D-238), und im **Papierkorb** steht nichts,
 * worauf ein Feld zeigen dürfte.*
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class NotAPossibleTarget extends DomainError
{
    /** D-238, D-890: jeder Knoten ausser einem Rahmenknoten ist wählbar — ein Rahmenknoten benennt einen Ort. */
    public static function itIsABranchRoot(string $name): self
    {
        return new self(sprintf('«%s» is a framework node and stands for a place, not for a thing in it.', $name));
    }

    /**
     * ⚠️ An inherited attribute belongs to an ancestor. Writing to its relation would change it for
     * every sibling too, and where a subtype's own narrowing would hang is not decided
     * ([OQ-086](../../../docs/NewConcept/91-open-questions.md)).
     */
    public static function notAnOwnField(int $relationId): self
    {
        return new self(sprintf(
            'Field %d is not one this node owns — an inherited field is changed where it is declared.',
            $relationId
        ));
    }

    /**
     * A converter name nobody answers to.
     *
     * ⚠️ **Refused rather than answered with the nearest thing.** *A stored name that resolves to
     * «none» would show a value unmapped on a field whose setting says it is mapped — a fault two
     * steps from its cause, which is the same reasoning
     * [D-360](../../../docs/NewConcept/90-decision-log.md) applies to a renderer nobody registered.*
     */
    public static function noConverterNamed(string $attempted): self
    {
        return new self(sprintf(
            'No converter answers to «%s».',
            $attempted
        ));
    }

    /**
     * ⚠️ *Wie bei einem Konverter: ein unbekannter Name wirft, statt «nichts zu beanstanden» zu
     * antworten. **Eine Prüfung, die still nicht läuft, ist schlimmer als keine** — die Zeile sieht
     * geprüft aus, und niemand erfährt, dass die Frage nie gestellt wurde. Seit D-845 gilt das für jede Zusatzfunktion.*
     */
    public static function thereIsNoSuchAddon(string $attempted): self
    {
        return new self(sprintf(
            'No add-on answers to «%s».',
            $attempted
        ));
    }

    public static function itIsInTheTrash(string $name): self
    {
        return new self(sprintf('«%s» is in the trash. Restore it before pointing at it.', $name));
    }
}
