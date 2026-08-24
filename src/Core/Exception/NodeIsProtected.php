<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * A node the engine stands on, asked to be deleted or moved.
 *
 * Framework protection covers only the few such nodes — it is protection, not provenance
 * (D-194).
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class NodeIsProtected extends DomainError
{
    public static function named(string $name): self
    {
        return new self(sprintf('«%s» is part of the framework and cannot be deleted or moved.', $name));
    }
}
