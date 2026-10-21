<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * A source that finds new orders itself — mail, eBay. The inbox calls it on its schedule and on the button.
 *
 * @see docs/pakete/import/konzept.md §2
 */
interface FetchingSource extends ImportSource
{
    /** @param string|null $cursor what the last batch handed back; null on the first run */
    public function fetch(?string $cursor): FetchBatch;

    /** Seconds between two scheduled runs; 0 means only by hand. */
    public function interval(): int;
}
