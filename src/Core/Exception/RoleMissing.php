<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * A module asked for a role (a node, field or value by its role name) that is not set on this site (D-1394).
 *
 * @see docs/gestaltung-und-architektur.md R9
 */
final class RoleMissing extends DomainError
{
    public static function named(string $group, string $key): self
    {
        return new self(sprintf('The role %s.%s is not set.', $group, $key));
    }
}
