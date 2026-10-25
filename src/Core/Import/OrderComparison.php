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
 * ⚠️ *The items of an order are only filled, never compared (D-986): a purchase whose prices are empty gets the source's
 * prices, matched by title, or as the only item of a one-item order. A price the purchase already holds stays — a
 * discount booked as a fee (the eBay case of 2026-10-21) is still corrected by hand.*
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

    /**
     * The purchases whose prices the source fills (D-986): only where the purchase has none, and only where it can be
     * told which source item it is.
     *
     * @return array<int, int> purchase record id => index into $proposal->lines
     */
    public static function lineFills(OrderProposal $proposal, ExistingOrder $existing): array
    {
        $fills = [];

        foreach ($existing->lines as $line) {
            if (in_array('unit_price', $line->filled, true)) {
                continue;
            }

            $index = self::sourceLine($proposal, $existing, $line);

            if ($index !== null && Money::toUnits($proposal->lines[$index]->unitPrice) !== null) {
                $fills[$line->recordId] = $index;
            }
        }

        return $fills;
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

    private static function sourceLine(OrderProposal $proposal, ExistingOrder $existing, ExistingLine $line): ?int
    {
        $title = self::lineTitle($line->title);
        $matches = [];

        foreach ($proposal->lines as $index => $candidate) {
            if ($title !== '' && self::lineTitle($candidate->title) === $title) {
                $matches[] = $index;
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        // ⚠️ *One item on both sides is the same item, whatever each calls it — a mail and the API name a board differently.*
        return $matches === [] && count($existing->lines) === 1 && count($proposal->lines) === 1 ? array_key_first($proposal->lines) : null;
    }

    /**
     * A title as both sides are compared: the mail import numbered its boards («Leiterplatte X (1)»), the API does not
     * («Leiterplatte X») — measured 2026-10-25 at W2025121606561472.
     */
    private static function lineTitle(string $title): string
    {
        return mb_strtolower(trim(preg_replace('/\s*\(\d+\)$/u', '', trim($title)) ?? $title));
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
