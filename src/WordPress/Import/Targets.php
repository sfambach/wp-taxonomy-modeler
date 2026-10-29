<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Import\TargetModel;
use Taxmod\WordPress\Persistence\UnitScaffold;

/**
 * Where taken proposals go — once for every source (D-953, S5): node and relation ids, never names. Stored in an
 * option and changed in the «Import» tab; the starting values are the ids tax_mail measured on fambach.net with
 * taxmod/read-node on 2026-10-20, and an option tax_mail stored itself is taken over.
 *
 * @see docs/pakete/import/konzept.md §3.6
 */
final class Targets
{
    public const OPTION = 'taxmod_import_targets';
    private const MAIL_OPTION = 'taxmail_targets';

    private const DEFAULTS = [
        'orders_node'       => 149000110051,
        'purchases_node'    => 149000109585,
        'origin_documented' => 149000108192,
        'amount_field'      => 4234,
        'unit_field'        => 4236,
        'order_fields'      => [
            'title'         => 149000103839,
            'origin'        => 149000108187,
            'origin_note'   => 149000108188,
            'order_number'  => 149000110055,
            'platform'      => 149000110056,
            'merchant'      => 149000110057,
            'ordered_at'    => 149000110058,
            'received_at'   => 149000110059,
            'shipping_cost' => 149000110060,
            'fees'          => 149000110061,
            'total'         => 149000110062,
            'tracking'      => 149000110063,
            'lines'         => 149000110064,
        ],
        'purchase_fields'   => [
            'title'             => 149000103839,
            'origin'            => 149000108187,
            'origin_note'       => 149000108188,
            'quantity'          => 149000109581,
            'article_number'    => 149000110052,
            'unit_price'        => 149000110048,
            'total_price'       => 149000110049,
            'landed_unit_price' => 149000110065,
            // Cost shares (D-1022): created with taxmod/apply on fambach.net, 2026-10-29.
            'shipping_share'    => 149000110184,
            'fees_share'        => 149000110185,
        ],
        // Sales (D-934, D-977): measured with taxmod/read-node on fambach.net, 2026-10-23.
        'sales_node'        => 149000110053,
        'sold_items_node'   => 149000110052,
        'sale_fields'       => [
            'title'         => 149000103839,
            'origin'        => 149000108187,
            'origin_note'   => 149000108188,
            'order_number'  => 149000110072,
            'platform'      => 149000110073,
            'merchant'      => 149000110074,
            'ordered_at'    => 149000110075,
            'shipped_at'    => 149000110076,
            'shipping_cost' => 149000110077,
            'fees'          => 149000110079,
            'total'         => 149000110080,
            'tracking'      => 149000110081,
            'lines'         => 149000110082,
        ],
        'sold_item_fields'  => [
            'title'          => 149000103839,
            'origin'         => 149000108187,
            'origin_note'    => 149000108188,
            'quantity'       => 149000110067,
            'article_number' => 149000110066,
            'unit_price'     => 149000110068,
        ],
        'currencies'        => ['EUR' => 8571, 'USD' => 8573, 'GBP' => 8582, 'AUD' => 149000109994],
        'required_parts'    => [149000110048, 149000110049],
        'sale_required_parts' => [149000110068],
    ];

    /** @return array<string, mixed> the stored map over the defaults; `mixed` because it nests */
    public static function raw(): array
    {
        $stored = get_option(self::OPTION, null);

        if (! is_array($stored)) {
            $stored = get_option(self::MAIL_OPTION, []);
        }

        return array_replace_recursive(self::DEFAULTS, is_array($stored) ? $stored : []);
    }

    public static function load(): TargetModel
    {
        $merged = self::raw();
        $ids = static fn (array $map): array => array_map('intval', $map);

        return new TargetModel(
            (int) $merged['orders_node'],
            (int) $merged['purchases_node'],
            (int) $merged['origin_documented'],
            (int) $merged['amount_field'],
            (int) $merged['unit_field'],
            $ids((array) $merged['order_fields']),
            $ids((array) $merged['purchase_fields']),
            $ids((array) $merged['currencies']),
            array_values($ids((array) $merged['required_parts'])),
            // The rate field is structure of the unit value, not a target to choose (D-068, D-966).
            UnitScaffold::rateFieldId(),
            (int) $merged['sales_node'],
            (int) $merged['sold_items_node'],
            $ids((array) $merged['sale_fields']),
            $ids((array) $merged['sold_item_fields']),
            array_values($ids((array) $merged['sale_required_parts'])),
        );
    }

    /** @param array<string, mixed> $map decoded from the tab; only known keys, only integers are kept */
    public static function save(array $map): void
    {
        $clean = [];

        foreach (self::DEFAULTS as $key => $default) {
            if (! array_key_exists($key, $map)) {
                continue;
            }

            if (is_array($default)) {
                $clean[$key] = array_map('absint', array_filter((array) $map[$key], 'is_numeric'));
            } elseif (is_numeric($map[$key])) {
                $clean[$key] = absint($map[$key]);
            }
        }

        update_option(self::OPTION, $clean, false);
    }
}
