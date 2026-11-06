<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * The model refused what a module asked through the door — an ability missing, a read or write turned down. The message
 * is the modeller's own; a module words it for its user.
 *
 * @see docs/gestaltung-und-architektur.md R2 (D-1186)
 */
final class ModelDoorRefused extends DomainError
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
