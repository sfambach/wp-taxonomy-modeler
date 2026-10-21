<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Import\ExistingOrder;
use Taxmod\Core\Import\LooseMatch;
use Taxmod\Core\Import\OrderComparison;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\TargetModel;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use WP_Error;

/**
 * The inbox writes and reads the model only through the abilities, as the logged-in user — the same path and rights
 * an outside importer has (D-936). Orders are read once per request, not per proposal (CD-7).
 *
 * @see docs/pakete/import/konzept.md §3.6
 */
final class ModelGateway
{
    /** @var array<string, ExistingOrder>|null platform|number (lower case) => order */
    private ?array $orders = null;

    /** @var array<string, string> platform|number => platform as written */
    private array $platforms = [];

    public function __construct(private readonly TargetModel $target)
    {
    }

    public static function available(): bool
    {
        return function_exists('wp_get_ability') && wp_get_ability('taxmod/write-data') !== null;
    }

    /**
     * @param list<array<string, mixed>> $changes
     * @return array<string, int>|WP_Error ref => record id
     */
    public function write(array $changes): array|WP_Error
    {
        $result = $this->execute('taxmod/write-data', ['changes' => $changes]);

        if ($result instanceof WP_Error) {
            return $result;
        }

        $this->orders = null;

        return array_map('intval', (array) ($result['refs'] ?? []));
    }

    public function findOrder(string $platform, string $orderNumber): ExistingOrder|WP_Error|null
    {
        if ($this->orders === null) {
            $loaded = $this->loadOrders();

            if ($loaded instanceof WP_Error) {
                return $loaded;
            }

            $this->orders = $loaded;
        }

        return $this->orders[self::key($platform, $orderNumber)] ?? null;
    }

    /** The order a proposal without a number belongs to, by seller or title within 14 days (LooseMatch). */
    public function findLoose(OrderProposal $proposal): ExistingOrder|WP_Error|null
    {
        if ($this->orders === null) {
            $loaded = $this->loadOrders();

            if ($loaded instanceof WP_Error) {
                return $loaded;
            }

            $this->orders = $loaded;
        }

        foreach ($this->orders as $key => $order) {
            if (mb_strtolower($this->platforms[$key] ?? '') === mb_strtolower(trim($proposal->platform)) && LooseMatch::matchesOrder($proposal, $order)) {
                return $order;
            }
        }

        return null;
    }

    /** @return array<string, ExistingOrder>|WP_Error */
    private function loadOrders(): array|WP_Error
    {
        $node = $this->execute('taxmod/read-node', ['node' => $this->target->ordersNode]);

        if ($node instanceof WP_Error) {
            return $node;
        }

        $platformField = $this->target->orderField('platform');
        $numberField = $this->target->orderField('order_number');
        $trackingField = $this->target->orderField('tracking');
        $receivedField = $this->target->orderField('received_at');
        $merchantField = $this->target->orderField('merchant');
        $orderedField = $this->target->orderField('ordered_at');
        $titleField = $this->target->orderField('title');
        $amountFields = [];

        foreach (['shipping_cost', 'fees', 'total'] as $role) {
            $amountFields[$this->target->orderField($role)] = $role;
        }

        $found = [];
        $partIds = [];
        $this->platforms = [];

        foreach ((array) ($node['records'] ?? []) as $record) {
            $platform = '';
            $number = '';
            $tracking = [];
            $received = null;
            $merchant = null;
            $ordered = null;
            $title = '';
            $parts = [];

            foreach ((array) ($record['values'] ?? []) as $value) {
                if (isset($amountFields[(int) $value['relation_id']])) {
                    $parts[$amountFields[(int) $value['relation_id']]] = (int) $value['value'];
                    $partIds[] = (int) $value['value'];

                    continue;
                }

                match ((int) $value['relation_id']) {
                    $platformField => $platform = (string) $value['value'],
                    $numberField   => $number = (string) $value['value'],
                    $trackingField => $tracking[] = (string) $value['value'],
                    $receivedField => $received = (string) $value['value'],
                    $merchantField => $merchant = (string) $value['value'],
                    $orderedField  => $ordered = (string) $value['value'],
                    $titleField    => $title .= ' ' . (string) $value['value'],
                    default        => null,
                };
            }

            if ($number !== '') {
                $found[self::key($platform, $number)] = [(int) $record['id'], $tracking, $received, $merchant, $ordered, trim($title), $parts];
                $this->platforms[self::key($platform, $number)] = trim($platform);
            }
        }

        // ⚠️ *The amounts are parts — records of their own. One read for all of them (`CD-7`), straight from the table,
        // because read-node would hand back every amount record of the installation (D-964).*
        $amounts = $this->amounts($partIds);
        $orders = [];

        foreach ($found as $key => [$id, $tracking, $received, $merchant, $ordered, $title, $parts]) {
            $values = array_filter([
                'merchant'   => $merchant,
                'ordered_at' => $ordered === null ? null : substr($ordered, 0, 10),
            ], static fn (?string $value): bool => $value !== null && $value !== '');

            foreach ($parts as $role => $partId) {
                if (isset($amounts[$partId])) {
                    $values[$role] = $amounts[$partId];
                }
            }

            $orders[$key] = new ExistingOrder($id, $tracking, $received, $merchant, $ordered, $title, $values);
        }

        return $orders;
    }

    /**
     * @param list<int> $partIds
     * @return array<int, string> part record id => «4.5 EUR»
     */
    private function amounts(array $partIds): array
    {
        $codes = array_flip($this->target->currencies);
        $out = [];

        foreach ((new WpdbRecordRepository())->valuesOfMany(array_values(array_unique($partIds))) as $partId => $values) {
            $amount = null;
            $unit = '';

            foreach ($values as $value) {
                if ($value->relationId === $this->target->amountField) {
                    $amount = $value->value->rawValue();
                } elseif ($value->relationId === $this->target->unitField) {
                    $unit = (string) ($codes[(int) $value->value->rawValue()] ?? $value->value->rawValue());
                }
            }

            $formatted = OrderComparison::amount($amount, $unit);

            if ($formatted !== null) {
                $out[(int) $partId] = $formatted;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>|WP_Error
     */
    private function execute(string $name, array $input): array|WP_Error
    {
        $ability = function_exists('wp_get_ability') ? wp_get_ability($name) : null;

        if ($ability === null) {
            return new WP_Error('taxmod_no_ability', __('The abilities of the Taxonomy Modeller are not available.', 'taxmod'));
        }

        $result = $ability->execute($input);

        return $result instanceof WP_Error ? $result : (array) $result;
    }

    private static function key(string $platform, string $orderNumber): string
    {
        return mb_strtolower(trim($platform)) . '|' . mb_strtolower(trim($orderNumber));
    }
}
