<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * Somebody else changed this object since it was read.
 *
 * `version` is the guard and it is deliberately coarse (D-P4c): the whole object is the unit,
 * so two people editing different fields of the same node still collide. That is the intended
 * trade — a collision the author can see beats a silent merge.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class ConcurrentChange extends DomainError
{
    public static function on(int $id, int $expected, int $found): self
    {
        return new self(sprintf(
            'Node %d was read at version %d but is now at version %d.',
            $id,
            $expected,
            $found
        ));
    }
}
