<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * A source that can look for one order on request — tax_mail's «Bestellung holen» (D-948).
 *
 * @see docs/pakete/import/konzept.md §3.8
 */
interface SearchingSource extends ImportSource
{
    public function search(string $text): FetchBatch;
}
