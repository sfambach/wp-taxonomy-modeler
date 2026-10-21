<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * A proposal without an order number — a won auction, an invoice — belongs to an order of the same platform with the
 * same seller or the same start of the item title, within 14 days; without a date on either side both must agree.
 * The rule of the Python importer, carried over from tax_mail so any source can use it.
 *
 * @see docs/pakete/import/konzept.md §3.8
 */
final class LooseMatch
{
    private const DAYS = 14;
    private const TITLE_HEAD = 15;

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
        return self::matches($loose, $order->counterparty, $order->orderDate, $order->title);
    }
}
