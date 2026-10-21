<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * A reviewed proposal cannot become a change list — nothing picked, a field not configured, an amount that is not one.
 *
 * @see docs/pakete/import/konzept.md §3.6
 */
final class WriteRefused extends DomainError
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
