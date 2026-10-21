<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * Folds the open proposals of one order — confirmation, shipping notice, an eBay record — into the one the review page
 * shows: the proposal that names priced items wins, tracking numbers add up, the rest fills gaps.
 *
 * @see docs/pakete/import/konzept.md §3.5
 */
final class ProposalGroup
{
    /** @param non-empty-list<OrderProposal> $proposals all with the same duplicate key */
    public static function merge(array $proposals): OrderProposal
    {
        usort($proposals, static fn (OrderProposal $a, OrderProposal $b): int => [self::rank($a), -count($a->lines)] <=> [self::rank($b), -count($b->lines)]);
        $base = $proposals[0];
        $first = static function (callable $field) use ($proposals): ?string {
            foreach ($proposals as $proposal) {
                $value = $field($proposal);

                if ($value !== null && $value !== '') {
                    return $value;
                }
            }

            return null;
        };
        $priced = array_values(array_filter($proposals, static fn (OrderProposal $p): bool => array_filter($p->lines, static fn (ProposalLine $l): bool => $l->unitPrice !== null) !== []));
        $withLines = array_values(array_filter($proposals, static fn (OrderProposal $p): bool => $p->lines !== []));
        $lines = ($priced[0] ?? $withLines[0] ?? $base)->lines;

        return new OrderProposal(
            $base->platform,
            $base->orderNumber,
            $base->kind,
            $first(static fn (OrderProposal $p): ?string => $p->orderDate),
            ($priced[0] ?? $base)->currency,
            $lines,
            $first(static fn (OrderProposal $p): ?string => $p->shippingCost),
            $first(static fn (OrderProposal $p): ?string => $p->total),
            $first(static fn (OrderProposal $p): ?string => $p->counterparty),
            $first(static fn (OrderProposal $p): ?string => $p->fees),
            $first(static fn (OrderProposal $p): ?string => $p->shippedDate),
            array_values(array_unique(array_merge(...array_map(static fn (OrderProposal $p): array => $p->trackingNumbers, $proposals)))),
            $base->source,
            $base->externalId,
        );
    }

    private static function rank(OrderProposal $proposal): int
    {
        return match ($proposal->kind) {
            ProposalKind::Ordered, ProposalKind::Sold => 0,
            ProposalKind::Invoice   => 1,
            ProposalKind::Shipped   => 2,
            ProposalKind::Delivered => 3,
            default                 => 4,
        };
    }
}
