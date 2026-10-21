<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * Where a proposal stands in the inbox.
 *
 * @see docs/pakete/import/konzept.md §3.3
 */
enum InboxStatus: string
{
    case Open = 'open';
    case Imported = 'imported';
    case Discarded = 'discarded';
}
