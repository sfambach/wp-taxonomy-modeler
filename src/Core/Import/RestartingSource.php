<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * A source that undoes its own side of a restart — tax_mail moves mails back into the INBOX (D-947). The inbox has
 * already forgotten the source's open proposals and its cursor when this runs.
 *
 * @see docs/pakete/import/konzept.md §3.8
 */
interface RestartingSource extends ImportSource
{
    public function restart(): void;
}
