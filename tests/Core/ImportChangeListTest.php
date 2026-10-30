<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Import\InvoiceFacts;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;
use Taxmod\Core\Import\ProposalLine;
use Taxmod\Core\Import\ChangeListBuilder;
use Taxmod\Core\Import\ExistingLine;
use Taxmod\Core\Import\ExistingOrder;
use Taxmod\Core\Import\Money;
use Taxmod\Core\Import\OrderComparison;
use Taxmod\Core\Import\TargetModel;
use Taxmod\Core\Exception\WriteRefused;
use Taxmod\Core\Port\ExchangeRates;

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
        // 5.95 shipping, half of the goods value is this item: its share 2.975; optional part, so a new one. The landed
        // price (5.00 + 2.975) / 10 is calculated in the model (D-1022), not written; no fees, no fee share.
        self::assertSame(['neu' => ['4234' => '2.975', '4236' => '8571']], $purchase['209']);
        self::assertArrayNotHasKey('207', $purchase);
        self::assertArrayNotHasKey('210', $purchase);

        $order = $changes[3]['values'];
        self::assertSame('@o', $changes[3]['record']);
        self::assertSame('Reichelt I-123456 · LM317', $order['101']);
        self::assertSame('Reichelt I-123456 · LM317 (+1)', $builder->newOrder($proposal, [0, 1], '')[5]['values']['101']);
        self::assertSame(['values' => ['@p0']], $order['113']);
        self::assertSame(['values' => ['99']], $order['102']);
        self::assertSame(['neu' => ['4234' => '16.95', '4236' => '8571']], $order['111']);
        self::assertFalse(isset($order['110']));
    }

    public function testAForeignAmountCarriesTheRateOfTheOrderDate(): void
    {
        $rates = new class implements ExchangeRates {
            /** @var list<string> */
            public array $asked = [];

            public function quoteOn(string $isoCode, string $date): ?string
            {
                $this->asked[] = $isoCode . ' ' . $date;

                return '1.1634';
            }
        };
        $proposal = new OrderProposal('JLCPCB', 'W1', ProposalKind::Ordered, '2026-09-21 01:02', 'USD', [
            new ProposalLine('PCB', 5, '2.00'),
        ], total: '22.29');

        $changes = (new ChangeListBuilder(self::target(300), $rates))->newOrder($proposal, [0], '');

        self::assertSame(['USD 2026-09-21'], $rates->asked, 'one rate per order, the order day');
        self::assertSame(['0' => ['4234' => '2', '4236' => '8573', '300' => '1.1634']], $changes[2]['values']['205']);
        self::assertSame(['neu' => ['4234' => '22.29', '4236' => '8573', '300' => '1.1634']], $changes[3]['values']['111']);
    }

    public function testNoRateInTheReferenceCurrencyOrWhenNoneIsKnown(): void
    {
        $none = new class implements ExchangeRates {
            public function quoteOn(string $isoCode, string $date): ?string
            {
                return null;
            }
        };
        $euro = new OrderProposal('Reichelt', '1', ProposalKind::Ordered, '2026-10-20', 'EUR', [new ProposalLine('LM317', 1, '0.50')]);
        $dollar = new OrderProposal('JLCPCB', 'W1', ProposalKind::Ordered, '2026-10-20', 'USD', [new ProposalLine('PCB', 1, '2.00')]);

        self::assertSame(['0' => ['4234' => '0.5', '4236' => '8571']], (new ChangeListBuilder(self::target(300), $none))->newOrder($euro, [0], '')[2]['values']['205']);
        self::assertSame(['0' => ['4234' => '2', '4236' => '8573']], (new ChangeListBuilder(self::target(300), $none))->newOrder($dollar, [0], '')[2]['values']['205']);
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

    public function testACompletedForeignAmountCarriesTheRateToo(): void
    {
        $rates = new class implements ExchangeRates {
            public function quoteOn(string $isoCode, string $date): ?string
            {
                return $isoCode === 'USD' && $date === '2026-09-21' ? '1.1634' : null;
            }
        };
        $proposal = new OrderProposal('JLCPCB', 'W1', ProposalKind::Ordered, '2026-09-21', 'USD', total: '22.29');

        self::assertSame(['neu' => ['4234' => '22.29', '4236' => '8573', '300' => '1.1634']], (new ChangeListBuilder(self::target(300), $rates))->completed(new ExistingOrder(7), $proposal, ['total'])[0]['values']['111']);
    }

    public function testAConflictingAmountOverwritesThePartItHas(): void
    {
        $existing = new ExistingOrder(7, values: ['total' => '176 EUR']);
        $proposal = new OrderProposal('eBay', '18-1', ProposalKind::Ordered, currency: 'EUR', total: '132');

        self::assertSame(['0' => ['4234' => '132', '4236' => '8571']], (new ChangeListBuilder(self::target()))->completed($existing, $proposal, ['total'])[0]['values']['111']);
    }

    /** D-986: a purchase without prices gets the source's, matched by title; a price it holds stays. */
    public function testEmptyItemPricesAreFilledFromTheSource(): void
    {
        $proposal = new OrderProposal('JLCPCB', 'W2', ProposalKind::Ordered, '2025-12-16', 'EUR', [
            new ProposalLine('Board A', 10, '0.50'),
            new ProposalLine('Board B', 5, '1.00'),
        ], shippingCost: '2.00');
        $existing = new ExistingOrder(7, lines: [
            new ExistingLine(41, 'board a', ['unit_price' => 410, 'total_price' => 411]),
            new ExistingLine(42, 'Board B', ['unit_price' => 420], ['unit_price']),
            new ExistingLine(43, 'Board C'),
        ]);

        self::assertSame([41 => 0], OrderComparison::lineFills($proposal, $existing), 'B has a price, C is no source item');

        $changes = (new ChangeListBuilder(self::target()))->completed($existing, $proposal, []);

        self::assertCount(1, $changes);
        self::assertSame('41', $changes[0]['record']);
        self::assertSame(['0' => ['4234' => '0.5', '4236' => '8571']], $changes[0]['values']['205'], 'the empty part it holds is filled');
        self::assertSame(['0' => ['4234' => '5', '4236' => '8571']], $changes[0]['values']['206']);
        // shipping 2.00 over 10.00 of goods, half of it this item: a share of 1 beside the price written from the same mail
        self::assertSame(['neu' => ['4234' => '1', '4236' => '8571']], $changes[0]['values']['209'], 'a part it lacks is created');
        self::assertArrayNotHasKey('207', $changes[0]['values'], 'the landed price is calculated (D-1022)');
    }

    /** D-1022: without share fields the landed price is written as before. */
    public function testWithoutShareFieldsTheLandedPriceIsWrittenAsBefore(): void
    {
        $proposal = new OrderProposal('Reichelt', 'I-1', ProposalKind::Ordered, '2026-10-20', 'EUR', [
            new ProposalLine('LM317', 10, '0.50'),
            new ProposalLine('Lochraster', 1, '5.00'),
        ], shippingCost: '5.95');

        $purchase = (new ChangeListBuilder(self::target(sharesAt: 0)))->newOrder($proposal, [0], '')[2]['values'];

        self::assertSame(['neu' => ['4234' => '0.7975', '4236' => '8571']], $purchase['207']);
        self::assertArrayNotHasKey('209', $purchase);
    }

    /** D-1022: an older price may already hold the shipping (D-933) — no share beside it. */
    public function testNoShareBesideAPriceTheRecordAlreadyHolds(): void
    {
        $proposal = new OrderProposal('JLCPCB', 'W2', ProposalKind::Ordered, '2025-12-16', 'EUR', [new ProposalLine('Board A', 10, '0.50')], shippingCost: '2.00');
        $existing = new ExistingOrder(7, lines: [new ExistingLine(41, 'board a', ['unit_price' => 410], ['unit_price'])]);

        $changes = (new ChangeListBuilder(self::target()))->completed($existing, $proposal, []);

        self::assertSame([], $changes, 'a line with a price is no line to fill, so it gets no share either');
    }

    public function testTheOnlyItemOfAnOrderIsTheSourcesOnlyItemWhateverItsName(): void
    {
        $proposal = new OrderProposal('JLCPCB', 'W3', ProposalKind::Ordered, '2025-12-16', 'EUR', [new ProposalLine('1541II_PowerAdapter', 10, '0.20')]);
        $existing = new ExistingOrder(7, lines: [new ExistingLine(41984, 'Leiterplatte 1541II_PowerAdapter (1)')]);

        self::assertSame([41984 => 0], OrderComparison::lineFills($proposal, $existing));
        self::assertSame([5 => 1], OrderComparison::lineFills(
            new OrderProposal('JLCPCB', 'W3', ProposalKind::Ordered, '2025-12-16', 'EUR', [new ProposalLine('Leiterplatte A', 5, '1'), new ProposalLine('Leiterplatte 1541II_PowerAdapter', 10, '0.20')]),
            new ExistingOrder(7, lines: [new ExistingLine(5, 'Leiterplatte 1541II_PowerAdapter (1)'), new ExistingLine(6, 'Leiterplatte B (2)')])
        ), 'the number the mail import appended does not count');
        self::assertSame([], OrderComparison::lineFills(
            new OrderProposal('JLCPCB', 'W3', ProposalKind::Ordered, '2025-12-16', 'EUR', [new ProposalLine('X', 1, '1'), new ProposalLine('Y', 1, '1')]),
            $existing
        ), 'two source items and no title match: not guessed');
    }

    public function testASaleGoesToTheSalesNodeWithoutTotalOrLandedPrice(): void
    {
        $proposal = new OrderProposal('eBay', '02-13629-54922', ProposalKind::Sold, '2025-09-24', 'EUR', [
            new ProposalLine('greaseweazle 4.1', 2, '25.00', '12345'),
        ], shippingCost: '4.50', total: '54.50', counterparty: 'ph_67684', fees: '0', shippedDate: '2025-09-25');

        self::assertNull(self::target()->forSales(), 'no sale targets, no sales');

        $changes = (new ChangeListBuilder(self::salesTarget()->forSales()))->newOrder($proposal, [0], 'Aus eBay.');

        self::assertSame(['op' => 'create_record', 'node' => 30, 'ref' => 'o'], $changes[0]);
        self::assertSame(['op' => 'create_record', 'node' => 40, 'ref' => 'p0'], $changes[1]);
        self::assertSame([
            '201' => 'greaseweazle 4.1',
            '403' => '2',
            '404' => '12345',
            '405' => ['0' => ['4234' => '25', '4236' => '8571']],
            '202' => ['values' => ['99']],
            '208' => 'Aus eBay.',
        ], $changes[2]['values'], 'a sold item has no total and no landed price; its price is a required part');

        $sale = $changes[3]['values'];
        self::assertSame('ph_67684', $sale['306']);
        self::assertSame('2025-09-25', $sale['314']);
        self::assertSame(['neu' => ['4234' => '4.5', '4236' => '8571']], $sale['309']);
        self::assertSame(['values' => ['@p0']], $sale['313']);
        self::assertSame([], (new ChangeListBuilder(self::salesTarget()->forSales()))->delivered(new ExistingOrder(7), '2025-09-30'), 'a sale has no received date');
    }

    private static function salesTarget(): TargetModel
    {
        $orders = self::target();

        return new TargetModel(10, 20, 99, 4234, 4236, $orders->orderFields, $orders->purchaseFields, $orders->currencies, [205, 206], null, 30, 40, [
            'title' => 101, 'origin' => 202, 'origin_note' => 208, 'order_number' => 304, 'platform' => 305,
            'merchant' => 306, 'ordered_at' => 307, 'shipped_at' => 314, 'shipping_cost' => 309, 'fees' => 310,
            'total' => 311, 'tracking' => 312, 'lines' => 313,
        ], [
            'title' => 201, 'origin' => 202, 'origin_note' => 208, 'quantity' => 403, 'article_number' => 404, 'unit_price' => 405,
        ], [405]);
    }

    public function testAnInvoiceBecomesItsOwnRecordLinkedToTheOrder(): void
    {
        $base = self::target();
        $target = new TargetModel(10, 20, 99, 4234, 4236, $base->orderFields, $base->purchaseFields, $base->currencies, [205, 206], null,
            invoicesNode: 50, invoiceFields: ['title' => 101, 'number' => 501, 'date' => 502, 'merchant' => 503, 'total' => 504, 'status' => 505,
                'payment_method' => 506, 'document' => 507, 'orders' => 508], paidStates: ['open' => 60, 'paid' => 61]);
        $proposal = new OrderProposal('Conrad', '2020147197', ProposalKind::Invoice, total: '61.17',
            invoice: new InvoiceFacts('9791312484', '2026-10-27', true, 'PayPal', 'vault:' . str_repeat('a', 32)));
        $changes = (new ChangeListBuilder($target))->invoice(new ExistingOrder(777), $proposal, 'From mail.');

        self::assertSame(['op' => 'create_record', 'node' => 50, 'ref' => 'r'], $changes[0], 'D-1024');
        self::assertSame([
            '101' => 'Rechnung 9791312484 · Conrad 2020147197', '501' => '9791312484', '502' => '2026-10-27', '503' => 'Conrad',
            '504' => ['neu' => ['4234' => '61.17', '4236' => '8571']], '505' => '61', '506' => 'PayPal', '507' => 'vault:' . str_repeat('a', 32),
            '508' => ['values' => ['777']],
        ], $changes[1]['values']);
        self::assertSame([], (new ChangeListBuilder($target))->invoice(new ExistingOrder(777, invoiceNumbers: ['9791312484']), $proposal, ''), 'once');
        self::assertSame([], (new ChangeListBuilder($base))->invoice(new ExistingOrder(777), $proposal, ''), 'without targets nothing');
    }

    private static function target(?int $rateField = null, int $sharesAt = 209): TargetModel
    {
        return new TargetModel(10, 20, 99, 4234, 4236, [
            'title' => 101, 'origin' => 102, 'origin_note' => 103, 'order_number' => 104, 'platform' => 105,
            'merchant' => 106, 'ordered_at' => 107, 'received_at' => 108, 'shipping_cost' => 109, 'fees' => 110,
            'total' => 111, 'tracking' => 112, 'lines' => 113,
        ], [
            'title' => 201, 'origin' => 202, 'quantity' => 203, 'article_number' => 204, 'unit_price' => 205,
            'total_price' => 206, 'landed_unit_price' => 207, 'origin_note' => 208, 'shipping_share' => $sharesAt, 'fees_share' => $sharesAt === 0 ? 0 : $sharesAt + 1,
        ], ['EUR' => 8571, 'USD' => 8573], [205, 206], $rateField);
    }
}
