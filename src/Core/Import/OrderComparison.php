<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * What a source says about an order the model already has, field by field (D-964): a field the model leaves empty is
 * filled, a field where both say something different is a conflict he decides.
 *
 * ```mermaid
 * flowchart LR
 *   S["source value"] --> E{"model empty?"}
 *   E -->|yes| F["fill"]
 *   E -->|no| D{"same?"}
 *   D -->|yes| N["nothing"]
 *   D -->|no| C["conflict: his choice"]
 * ```
 *
 * ⚠️ *Order fields only. The items of an order (price per piece, a discount booked as a fee) are not compared — that
 * is the eBay case of 2026-10-21 the eBay thread corrected by hand (INFERRED: the decision named fields, not lines).*
 *
 * @see docs/pakete/import/konzept.md §3.4
 */
final class OrderComparison
{
    public const ROLES = ['merchant', 'ordered_at', 'shipping_cost', 'fees', 'total'];
    private const AMOUNTS = ['shipping_cost', 'fees', 'total'];

    /** @return array<string, array{model: ?string, source: string, conflict: bool}> role => both sides, only where they differ */
    public static function between(OrderProposal $proposal, ExistingOrder $existing): array
    {
        $differences = [];

        foreach (self::ROLES as $role) {
            $source = self::sourceValue($proposal, $role);

            if ($source === null) {
                continue;
            }

            $model = $existing->values[$role] ?? null;
            $model = $model === null || trim($model) === '' ? null : self::normalised($role, $model);

            if ($model === $source) {
                continue;
            }

            $differences[$role] = ['model' => $model, 'source' => $source, 'conflict' => $model !== null];
        }

        return $differences;
    }

    /** @return list<string> the roles a source fills without asking */
    public static function fills(OrderProposal $proposal, ExistingOrder $existing): array
    {
        return array_keys(array_filter(self::between($proposal, $existing), static fn (array $side): bool => ! $side['conflict']));
    }

    public static function hasConflict(OrderProposal $proposal, ExistingOrder $existing): bool
    {
        return array_filter(self::between($proposal, $existing), static fn (array $side): bool => $side['conflict']) !== [];
    }

    /** An amount in the form both sides are compared in. */
    public static function amount(?string $amount, string $currency): ?string
    {
        $units = Money::toUnits($amount);

        return $units === null ? null : Money::format($units) . ' ' . strtoupper(trim($currency));
    }

    private static function sourceValue(OrderProposal $proposal, string $role): ?string
    {
        $value = match ($role) {
            'merchant'      => $proposal->counterparty,
            'ordered_at'    => $proposal->orderDate,
            'shipping_cost' => self::amount($proposal->shippingCost, $proposal->currency),
            'fees'          => self::amount($proposal->fees, $proposal->currency),
            'total'         => self::amount($proposal->total, $proposal->currency),
            default         => null,
        };

        return $value === null || trim($value) === '' ? null : self::normalised($role, $value);
    }

    private static function normalised(string $role, string $value): string
    {
        if ($role === 'ordered_at') {
            return substr(trim($value), 0, 10);
        }

        if (in_array($role, self::AMOUNTS, true)) {
            $parts = preg_split('/\s+/', trim($value)) ?: [];

            return self::amount($parts[0] ?? null, $parts[1] ?? '') ?? trim($value);
        }

        return trim($value);
    }
}
