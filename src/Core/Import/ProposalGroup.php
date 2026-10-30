<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * Folds the open proposals of one order — confirmation, invoice, shipping and delivery notice — into the one the review
 * page shows, as tax_mail's merger did: the confirmation's items and amounts win, a numbered proposal is the base over
 * one without a number, tracking numbers add up, the rest fills gaps.
 *
 * @see docs/pakete/import/konzept.md §3.8
 */
final class ProposalGroup
{
    /** @param non-empty-list<OrderProposal> $proposals all of one order */
    public static function merge(array $proposals): OrderProposal
    {
        // Best source first: confirmation, then most items, then most article numbers (an invoice names them, a
        // won-auction mail cuts titles short).
        usort($proposals, static fn (OrderProposal $a, OrderProposal $b): int => [self::rank($a), -count($a->lines), -self::articleNumbers($a)]
            <=> [self::rank($b), -count($b->lines), -self::articleNumbers($b)]);
        $first = static function (callable $field) use ($proposals): ?string {
            foreach ($proposals as $proposal) {
                $value = $field($proposal);

                if ($value !== null && $value !== '') {
                    return $value;
                }
            }

            return null;
        };

        $priced = array_values(array_filter($proposals, static fn (OrderProposal $p): bool => $p->lines !== []
            && array_filter($p->lines, static fn (ProposalLine $line): bool => $line->unitPrice !== null) !== []));
        $lines = $priced !== [] ? $priced[0]->lines : LineUnion::of(array_merge(...array_map(static fn (OrderProposal $p): array => $p->lines, $proposals)));
        $kinds = array_map(static fn (OrderProposal $p): ProposalKind => $p->kind, $proposals);
        $numbered = array_values(array_filter($proposals, static fn (OrderProposal $p): bool => ! $p->numberMissing));
        $base = $numbered[0] ?? $proposals[0];

        return new OrderProposal(
            $base->platform,
            $base->orderNumber,
            in_array(ProposalKind::Ordered, $kinds, true) ? ProposalKind::Ordered : $base->kind,
            $first(static fn (OrderProposal $p): ?string => $p->kind === ProposalKind::Ordered ? $p->orderDate : null) ?? self::earliest($proposals),
            $priced !== [] ? $priced[0]->currency : $base->currency,
            $lines,
            $first(static fn (OrderProposal $p): ?string => $p->shippingCost),
            $first(static fn (OrderProposal $p): ?string => $p->total),
            $first(static fn (OrderProposal $p): ?string => $p->counterparty),
            $first(static fn (OrderProposal $p): ?string => $p->fees),
            $first(static fn (OrderProposal $p): ?string => $p->shippedDate),
            array_values(array_unique(array_merge(...array_map(static fn (OrderProposal $p): array => $p->trackingNumbers, $proposals)))),
            $first(static fn (OrderProposal $p): ?string => $p->source),
            $base->externalId,
            $base->numberMissing,
            array_values(array_filter(array_map(static fn (OrderProposal $p): ?InvoiceFacts => $p->invoice, $proposals)))[0] ?? null,
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

    private static function articleNumbers(OrderProposal $proposal): int
    {
        return count(array_filter($proposal->lines, static fn (ProposalLine $line): bool => $line->articleNumber !== null && $line->articleNumber !== ''));
    }

    /** @param list<OrderProposal> $proposals */
    private static function earliest(array $proposals): ?string
    {
        $dates = array_values(array_filter(array_map(static fn (OrderProposal $p): ?string => $p->orderDate, $proposals)));
        sort($dates);

        return $dates[0] ?? null;
    }
}
