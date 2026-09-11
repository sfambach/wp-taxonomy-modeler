<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * Ein Klassenname, der nicht im Inventar der Knotenklassen steht (D-716).
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class UnknownNodeClass extends DomainError
{
    public static function named(string $class): self
    {
        return new self(sprintf('«%s» is not a node class.', $class));
    }
}
