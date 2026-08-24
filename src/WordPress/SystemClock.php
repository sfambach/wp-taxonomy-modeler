<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\Core\Repository\Clock;

/**
 * Now, in the site's timezone.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class SystemClock implements Clock
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', wp_timezone());
    }
}
