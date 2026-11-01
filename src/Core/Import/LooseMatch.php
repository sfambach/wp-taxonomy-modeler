<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * A proposal without an order number — a won auction, an invoice — belongs to an order of the same platform with the
 * same seller or the same start of the item title, within 14 days; without a date on either side both must agree.
 * The rule of the Python importer, carried over from tax_mail so any source can use it. A parcel mail that names only
 * its tracking number belongs to the order that carries that number, whatever its date or title (D-1017); one that
 * also names its items belongs to the one order of its shop without tracking whose items they all are (D-1067).
 *
 * @see docs/pakete/import/konzept.md §3.8
 */
final class LooseMatch
{
    private const DAYS = 14;
    private const TITLE_HEAD = 15;
    private const PARCEL_DAYS = 60;

    /**
     * @param string|null $counterparty the other order's seller
     * @param string|null $orderDate the other order's date, Y-m-d…
     * @param string $titles the other order's title or item titles, any text
     */
    public static function matches(OrderProposal $loose, ?string $counterparty, ?string $orderDate, string $titles): bool
    {
        $sameSeller = $loose->counterparty !== null && $counterparty !== null && $counterparty !== ''
            && mb_strtolower(trim($loose->counterparty, ' …')) === mb_strtolower(trim($counterparty, ' …'));
        $head = mb_strtolower(mb_substr(rtrim($loose->lines[0]->title ?? '', '.… '), 0, self::TITLE_HEAD));
        $sameTitle = $head !== '' && str_contains(mb_strtolower($titles), $head);

        if ($loose->orderDate === null || $orderDate === null || $orderDate === '') {
            return $sameSeller && $sameTitle;
        }

        $days = abs((int) round((strtotime(substr($orderDate, 0, 10)) - strtotime(substr($loose->orderDate, 0, 10))) / 86400));

        return $days <= self::DAYS && ($sameSeller || $sameTitle);
    }

    public static function matchesOrder(OrderProposal $loose, ExistingOrder $order): bool
    {
        return self::sharesTracking($loose->trackingNumbers, $order->trackingNumbers)
            || self::matches($loose, $order->counterparty, $order->orderDate, $order->title);
    }

    /**
     * ⚠️ *A shop's parcel mail names its items but not its order (AliExpress «Packstück …»). It belongs to an order of the
     * same shop that has no tracking number yet, ordered at most {@see self::PARCEL_DAYS} days before it was shipped, when
     * every item's title head is among the order's titles. Stefan, 2026-11-01: «daraus könne man evtl auch die zuornung
     * zur sendungs nummer herstellen» (D-1067).*
     */
    public static function parcelMatches(OrderProposal $loose, ExistingOrder $order): bool
    {
        if (! $loose->numberMissing || $loose->lines === [] || $order->trackingNumbers !== []
            || ! in_array($loose->kind, [ProposalKind::Shipped, ProposalKind::Delivered], true)) {
            return false;
        }

        if ($loose->shippedDate !== null && $order->orderDate !== null && $order->orderDate !== '') {
            $days = (int) round((strtotime(substr($loose->shippedDate, 0, 10)) - strtotime(substr($order->orderDate, 0, 10))) / 86400);

            if ($days < 0 || $days > self::PARCEL_DAYS) {
                return false;
            }
        }

        $titles = mb_strtolower($order->title . ' ' . implode(' ', array_map(static fn (ExistingLine $line): string => $line->title, $order->lines)));

        foreach ($loose->lines as $line) {
            $head = mb_strtolower(mb_substr(rtrim($line->title, '.… '), 0, self::TITLE_HEAD));

            if ($head === '' || ! str_contains($titles, $head)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $loose
     * @param list<string> $other
     */
    public static function sharesTracking(array $loose, array $other): bool
    {
        return $loose !== [] && array_intersect($loose, $other) !== [];
    }
}
