<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * One module that talks to one counterpart — a mailbox, eBay, JLCPCB — and turns its answers into proposals (D-952).
 * Registered through the filter `taxmod_import_sources`; what it can do beyond naming itself, the role interfaces say.
 *
 * @see docs/pakete/import/konzept.md §2
 */
interface ImportSource
{
    /** Slug, also the key of its configuration tab (D-945): 'mail', 'ebay', 'jlcpcb'. */
    public function key(): string;

    /** Translated by the module (AR-2). */
    public function label(): string;
}
