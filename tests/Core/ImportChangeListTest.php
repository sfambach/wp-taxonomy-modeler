<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;
use Taxmod\Core\Import\ProposalLine;
use Taxmod\Core\Import\ChangeListBuilder;
use Taxmod\Core\Import\ExistingOrder;
use Taxmod\Core\Import\Money;
use Taxmod\Core\Import\OrderComparison;
use Taxmod\Core\Import\TargetModel;
use Taxmod\Core\Exception\WriteRefused;

final class ImportChangeListTest extends TestCase
{
    public function testPickedItemsOnlyAndLandedPriceOverAllItems(): void
    {
        $proposal = new OrderProposal('Reichelt', 'I-123456', ProposalKind::Ordered, '2026-10-20', 'EUR', [
            new ProposalLine('LM317', 10, '0.50', 'LM 317'),
            new ProposalLine('Lochraster', 1, '5.00', 'H25PR'),
        ], shippingCost: '5.95', total: '16.95', counterparty: 'reichelt elektronik');

        $builder = new ChangeListBuilder(self::target());
        $changes = $builder->newOrder($proposal, [0], 'Aus Mail.');

        self::assertCount(4, $changes);
        self::assertSame(['op' => 'create_record', 'node' => 10, 'ref' => 'o'], $changes[0]);
        self::assertSame(['op' => 'create_record', 'node' => 20, 'ref' => 'p0'], $changes[1]);

        $purchase = $changes[2]['values'];
        self::assertSame('LM317', $purchase['201']);
        self::assertSame('10', $purchase['203']);
        self::assertSame(['0' => ['4234' => '0.5', '4236' => '8571']], $purchase['205']);
        self::assertSame(['0' => ['4234' => '5', '4236' => '8571']], $purchase['206']);
        // 5.95 shipping, half of the goods value is this item: (5.00 + 2.975) / 10; optional part, so a new one
        self::assertSame(['neu' => ['4234' => '0.7975', '4236' => '8571']], $purchase['207']);

        $order = $changes[3]['values'];
        self::assertSame('@o', $changes[3]['record']);
        self::assertSame('Reichelt I-123456 · LM317', $order['101']);
        self::assertSame('Reichelt I-123456 · LM317 (+1)', $builder->newOrder($proposal, [0, 1], '')[5]['values']['101']);
        self::assertSame(['values' => ['@p0']], $order['113']);
        self::assertSame(['values' => ['99']], $order['102']);
        self::assertSame(['neu' => ['4234' => '16.95', '4236' => '8571']], $order['111']);
        self::assertFalse(isset($order['110']));
    }

    public function testNothingPickedIsRefused(): void
    {
        $this->expectException(WriteRefused::class);
        (new ChangeListBuilder(self::target()))->newOrder(new OrderProposal('X', '1', ProposalKind::Ordered), [], '');
    }

    public function testShippedAddsOnlyNewTrackingNumbers(): void
    {
        $builder = new ChangeListBuilder(self::target());

        self::assertSame([], $builder->shipped(new ExistingOrder(5, ['A1']), ['A1']));
        self::assertSame(
            [['op' => 'set_values', 'record' => '5', 'values' => ['112' => ['values' => ['A1', 'B2']]]]],
            $builder->shipped(new ExistingOrder(5, ['A1']), ['B2'])
        );
    }

    public function testMoneyRoundTrip(): void
    {
        self::assertSame(123400, Money::toUnits('12.34'));
        self::assertSame(1235, Money::toUnits('0.12345'));
        self::assertSame('12.3', Money::format(Money::toUnits('12.30')));
        self::assertSame('-0.05', Money::format(-500));
        self::assertSame('3', Money::format(30000));
    }

    public function testCompletionFillsEmptyFieldsAndOnlyPickedConflicts(): void
    {
        // The eBay case of 2026-10-21: no shipping and total in the model, a merchant that differs.
        $existing = new ExistingOrder(7, values: ['merchant' => 'gxjs014', 'ordered_at' => '2026-09-28', 'fees' => '1 EUR']);
        $proposal = new OrderProposal('eBay', '18-1', ProposalKind::Ordered, '2026-09-28 10:00:00', 'EUR', shippingCost: '4.50', total: '132.00', counterparty: 'gxjs014-de', fees: '1.00');

        self::assertSame(['merchant', 'shipping_cost', 'total'], array_keys(OrderComparison::between($proposal, $existing)));
        self::assertTrue(OrderComparison::between($proposal, $existing)['merchant']['conflict']);
        self::assertSame(['shipping_cost', 'total'], OrderComparison::fills($proposal, $existing));

        $builder = new ChangeListBuilder(self::target());
        $filled = $builder->completed($existing, $proposal, OrderComparison::fills($proposal, $existing));
        self::assertSame([['op' => 'set_values', 'record' => '7', 'values' => [
            '109' => ['neu' => ['4234' => '4.5', '4236' => '8571']],
            '111' => ['neu' => ['4234' => '132', '4236' => '8571']],
        ]]], $filled);

        $picked = $builder->completed($existing, $proposal, ['merchant']);
        self::assertSame(['106' => 'gxjs014-de'], $picked[0]['values']);
        self::assertSame([], $builder->completed($existing, $proposal, ['fees']), 'an equal field is not written');
    }

    public function testAConflictingAmountOverwritesThePartItHas(): void
    {
        $existing = new ExistingOrder(7, values: ['total' => '176 EUR']);
        $proposal = new OrderProposal('eBay', '18-1', ProposalKind::Ordered, currency: 'EUR', total: '132');

        self::assertSame(['0' => ['4234' => '132', '4236' => '8571']], (new ChangeListBuilder(self::target()))->completed($existing, $proposal, ['total'])[0]['values']['111']);
    }

    private static function target(): TargetModel
    {
        return new TargetModel(10, 20, 99, 4234, 4236, [
            'title' => 101, 'origin' => 102, 'origin_note' => 103, 'order_number' => 104, 'platform' => 105,
            'merchant' => 106, 'ordered_at' => 107, 'received_at' => 108, 'shipping_cost' => 109, 'fees' => 110,
            'total' => 111, 'tracking' => 112, 'lines' => 113,
        ], [
            'title' => 201, 'origin' => 202, 'quantity' => 203, 'article_number' => 204, 'unit_price' => 205,
            'total_price' => 206, 'landed_unit_price' => 207, 'origin_note' => 208,
        ], ['EUR' => 8571], [205, 206]);
    }
}
