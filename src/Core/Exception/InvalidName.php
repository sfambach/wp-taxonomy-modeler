<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * A name that the model will not accept.
 *
 * Names are required and deliberately **not** unique (D-022) — what is refused here is
 * emptiness and nothing else.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class InvalidName extends DomainError
{
    public static function empty(): self
    {
        return new self('A node needs a name.');
    }
}
