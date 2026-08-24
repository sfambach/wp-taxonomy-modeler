<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * No node carries this id.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class NodeNotFound extends DomainError
{
    public static function withId(int $id): self
    {
        return new self(sprintf('No node with id %d.', $id));
    }
}
