<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * What a source may bring for a platform (D-953, S4).
 *
 * @see docs/pakete/import/konzept.md §3.4
 */
enum DutyRole: string
{
    /** New orders and sales. */
    case Orders = 'orders';
    /** Shipped, delivered, tracking numbers of an order that is in the model. */
    case Status = 'status';
    /** Prices of an order another source brought. */
    case Prices = 'prices';
}
