<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

/**
 * Ids 1, 2, 3 … — genug für einen Test, und sie kommen nie zweimal.
 *
 * ⚠️ **Seit TASK-004 keine Schnittstelle des Kerns mehr.** *`IdentityAllocator` ist gestrichen, weil
 * jede Tabelle ihre Ids selbst vergibt ([`package.md` §6](../../../docs/pakete/modelltabellen/package.md)).
 * Was hier bleibt, ist ein Zähler für Testaufbauten, die einem Knoten oder einer Kante eine feste
 * Nummer geben wollen — **nicht** die Art, wie die Anwendung Ids bekommt.*
 */
final class CountingIdentities
{
    private int $last = 0;

    public function next(): int
    {
        return ++$this->last;
    }
}
