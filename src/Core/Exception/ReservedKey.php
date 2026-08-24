<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

/**
 * A free setting trying to take one of the engine's names.
 *
 * ⚠️ **Checked at write time** (D-084) — an author who defines a setting called `hide` would
 * silently break rendering, and the failure would surface far from its cause.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class ReservedKey extends DomainError
{
    public static function named(string $key): self
    {
        return new self(sprintf('«%s» is one of the engine\'s own settings and cannot be redefined.', $key));
    }
}
