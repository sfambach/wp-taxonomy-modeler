<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Import\ExistingOrder;
use Taxmod\Core\Import\LooseMatch;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\TargetModel;
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
        $orders = [];
        $this->platforms = [];

        foreach ((array) ($node['records'] ?? []) as $record) {
            $platform = '';
            $number = '';
            $tracking = [];
            $received = null;
            $merchant = null;
            $ordered = null;
            $title = '';

            foreach ((array) ($record['values'] ?? []) as $value) {
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
                $orders[self::key($platform, $number)] = new ExistingOrder((int) $record['id'], $tracking, $received, $merchant, $ordered, trim($title));
                $this->platforms[self::key($platform, $number)] = trim($platform);
            }
        }

        return $orders;
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
