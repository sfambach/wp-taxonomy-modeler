<?php declare(strict_types=1);

namespace Taxmod\Core\Event;

/**
 * An import source reported that an order was shipped or delivered (D-1037, D-1499). The inbox only reports it, once everything
 * is written; what follows — the order's status — belongs to the stock process that listens (D-1038).
 *
 * @see docs/pakete/ereignisse/konzept.md
 */
final class OrderReported
{
    use EventId;

    public const SHIPPED   = 'shipped';
    public const DELIVERED = 'delivered';

    public function __construct(
        public readonly int $orderRecordId,
        /** {@see self::SHIPPED} or {@see self::DELIVERED}. */
        public readonly string $kind,
        /** Y-m-d, or '' where the message names no day (an archive message, D-1350). */
        public readonly string $date,
        /** `mail` or `api`. */
        public readonly string $channel,
        /** The source's own reference of the message. */
        public readonly string $reference,
    ) {
        if (! in_array($kind, [self::SHIPPED, self::DELIVERED], true)) {
            throw new \InvalidArgumentException("No order report «{$kind}».");
        }
    }
}
