<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * The inbox could not read the orders of the model — no abilities, no right, a broken target id.
 *
 * @see docs/pakete/import/konzept.md §3.8
 */
final class ModelNotReadable extends DomainError
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
