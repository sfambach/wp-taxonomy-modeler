<?php declare(strict_types=1);

namespace Taxmod\Core\Rules;

/**
 * D-1569: one rule one place up or down in the order its rule book checks them — by position, then id.
 *
 * Two neighbours with different positions swap them; two with the same position cannot be told apart by a swap, so then
 * the whole list is numbered anew in steps of ten, in the new order.
 *
 * @see docs/NewConcept/90-decision-log.md D-1569
 */
final class RuleOrder
{
    /**
     * @param array<int, int> $positions rule → position, in the order they are checked
     * @param int             $step      -1 up, +1 down
     * @return array<int, int> rule → its new position, only the rules that change
     */
    public static function move(array $positions, int $rule, int $step): array
    {
        $ids  = array_keys($positions);
        $at   = array_search($rule, $ids, true);
        $with = $at === false ? null : $at + $step;

        if ($at === false || ($step !== -1 && $step !== 1) || ! isset($ids[$with])) {
            throw new \DomainException('The rule cannot move that way.');
        }

        $other = $ids[$with];

        if ($positions[$rule] !== $positions[$other]) {
            return [$rule => $positions[$other], $other => $positions[$rule]];
        }

        [$ids[$at], $ids[$with]] = [$other, $rule];
        $out = [];

        foreach ($ids as $i => $id) {
            if ($positions[$id] !== ($i + 1) * 10) {
                $out[$id] = ($i + 1) * 10;
            }
        }

        return $out;
    }
}
