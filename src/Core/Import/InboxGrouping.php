<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * Which open proposals the review page shows as one order: same platform and order number; a proposal without a
 * number joins the open order it belongs to (LooseMatch) — one with a number first, else another without one.
 *
 * @see docs/pakete/import/konzept.md §3.8
 */
final class InboxGrouping
{
    /**
     * @param array<int, OrderProposal> $proposals row id => proposal, oldest first
     * @param array<int, string|null> $receivedDays row id => Y-m-d the source received it, for proposals without a date
     * @return list<non-empty-list<int>> row ids per order
     */
    public static function group(array $proposals, array $receivedDays = []): array
    {
        $groups = [];

        foreach ($proposals as $id => $proposal) {
            $number = trim($proposal->orderNumber);
            $key = $number === '' ? 'row:' . $id : mb_strtolower(trim($proposal->platform)) . '|' . mb_strtolower($number);
            $groups[$key][] = $id;
        }

        $first = static fn (string $key): OrderProposal => $proposals[$groups[$key][0]];
        $keys = array_keys($groups);
        usort($keys, static fn (string $a, string $b): int => [$first($a)->numberMissing, $a] <=> [$first($b)->numberMissing, $b]);

        foreach (array_keys($groups) as $key) {
            if (! isset($groups[$key]) || count($groups[$key]) !== 1 || ! $first($key)->numberMissing) {
                continue;
            }

            $loose = $first($key);

            foreach ($keys as $otherKey) {
                if ($otherKey === $key || ! isset($groups[$otherKey])) {
                    continue;
                }

                $other = $first($otherKey);

                if (mb_strtolower($other->platform) !== mb_strtolower($loose->platform)) {
                    continue;
                }

                $titles = implode(' ', array_map(static fn (ProposalLine $line): string => $line->title, $other->lines));
                $date = $other->orderDate ?? ($receivedDays[$groups[$otherKey][0]] ?? null);

                if (LooseMatch::sharesTracking($loose->trackingNumbers, $other->trackingNumbers) || LooseMatch::matches($loose, $other->counterparty, $date, $titles)) {
                    $groups[$otherKey][] = $groups[$key][0];
                    unset($groups[$key]);
                    break;
                }
            }
        }

        return array_values($groups);
    }
}
