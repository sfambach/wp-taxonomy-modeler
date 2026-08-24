<?php declare(strict_types=1);

namespace Taxmod\Core\Repository;

/**
 * Now — asked for rather than taken, so the core stays testable.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
