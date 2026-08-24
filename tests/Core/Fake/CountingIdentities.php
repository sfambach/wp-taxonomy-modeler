<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Repository\IdentityAllocator;

/** Ids 1, 2, 3 … — enough for a test, and it never reissues, which is the rule it stands in for. */
final class CountingIdentities implements IdentityAllocator
{
    private int $last = 0;

    public function next(): int
    {
        return ++$this->last;
    }
}
