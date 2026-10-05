<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * Ein Kind soll eine Klasse bekommen, die die Klasse seines Vaters nicht erlaubt (Anforderung 2.2.2).
 *
 * ⚠️ **Sein Grund** ([D-716](../../../docs/NewConcept/90-decision-log.md)): *«wenn wir jede klasse
 * erlauben, würde das die vererbung durchbrechen, da ein kind eine klasse wählen könnte, die nicht vom
 * vater definiert wird.»*
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class ClassNotAllowedUnder extends DomainError
{
    public static function parent(string $childClass, string $parentClass): self
    {
        return new self(sprintf('A node of class «%s» may not have a child of class «%s».', $parentClass, $childClass));
    }
}
