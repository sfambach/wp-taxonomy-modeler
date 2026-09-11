<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * Eine Multiplizität, die die Zielklasse des Felds nicht erlaubt (Modell 1.2.3).
 *
 * ⚠️ **Sein Wort** ([D-713](../../../docs/NewConcept/90-decision-log.md)): *«bool = 1..1, an
 * knotenklasse bool»* — und die Regel dahinter: *eine Klasse schränkt nur ein, wenn sie kein «leer»
 * kennt.*
 *
 * @see docs/modell-anforderungen.md
 */
final class MultiplicityNotAllowed extends DomainError
{
    public static function byTarget(string $multiplicity, string $targetClass): self
    {
        return new self(sprintf('A field on «%s» may not be «%s».', $targetClass, $multiplicity));
    }
}
