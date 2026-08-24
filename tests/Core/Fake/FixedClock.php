<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Repository\Clock;

/** A clock that does not move, so a test never depends on when it ran. */
final class FixedClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-08-24 12:00:00');
    }
}
