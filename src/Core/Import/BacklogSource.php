<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * A source with an old stock to sort — tax_mail's mails from before its starting point (D-947, D-948). Run by hand, in
 * slices; the source asks the model itself and may move what is already there.
 *
 * @see docs/pakete/import/konzept.md §3.8
 */
interface BacklogSource extends ImportSource
{
    /** One slice; a null cursor in the batch means the backlog is done. */
    public function backlog(?string $cursor, ModelLookup $model): FetchBatch;
}
