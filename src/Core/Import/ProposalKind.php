<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * What a source says about an order; the kinds the mail import knew, shared by every source (D-953).
 *
 * @see docs/pakete/import/konzept.md §3.2
 */
enum ProposalKind: string
{
    case Ordered = 'ordered';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Invoice = 'invoice';
    case Sold = 'sold';
    /** About an order, but nothing to write — customer service, an invoice without order number. Discard only. */
    case Other = 'other';
    /** A shop message without an order in it — advertising, newsletter. */
    case NoReceipt = 'no_receipt';
}
