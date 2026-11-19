<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Import\ChangeListBuilder;
use Taxmod\Core\Import\ExistingOrder;
use Taxmod\Core\Import\OrderComparison;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\PlatformFirms;
use Taxmod\Core\Import\ProposalKind;
use Taxmod\Core\Import\ProposalLine;
use Taxmod\Core\Import\TargetModel;

/** «Plattform» as a reference to a firm (D-1487): a source's name is resolved, the model keeps the firm. */
final class ImportPlatformFirmsTest extends TestCase
{
    public function testANameNamesAFirmOnlyWhenExactlyOneHasItAsItsWord(): void
    {
        $firms = self::firms();

        self::assertSame(46727, $firms->idOf('aliexpress'));
        self::assertSame(46727, $firms->idOf(' AliExpress '));
        self::assertSame(35197, $firms->idOf('Mouser'), 'one firm begins with it as a whole word');
        self::assertNull($firms->idOf('Mous'), 'a part of a word is not a word');
        self::assertNull($firms->idOf('Conrad'), 'two firms begin with it: none');
        self::assertSame(9, $firms->idOf('conrad electronic'), 'the exact word wins over the beginnings');
        self::assertNull($firms->idOf('Doppel'), 'two firms with one word name none');
        self::assertNull($firms->idOf(''));
        self::assertSame('AliExpress', $firms->canonical('ALIEXPRESS'));
        self::assertSame('', $firms->canonical('PayPal'));
        self::assertSame('Mouser Electronics', $firms->wordOf(35197));
        self::assertNull($firms->wordOf(1));
    }

    public function testTheTargetWritesTextWhileTheFieldIsTextAndTheFirmOnceItIsAReference(): void
    {
        self::assertSame('AliExpress', self::target()->platformValue('AliExpress'));
        self::assertSame('46727', self::target(self::firms())->platformValue('aliexpress'));
        self::assertNull(self::target(self::firms())->platformValue('PayPal'));
        self::assertSame('46727', self::target()->withPlatformFirms(self::firms())->platformValue('AliExpress'), 'the firms come with the orders read');
    }

    public function testANewOrderGetsTheFirmOrNoPlatform(): void
    {
        $line = [new ProposalLine('LM317', 1, '0.50', 'LM 317')];
        $found = (new ChangeListBuilder(self::target(self::firms())))->newOrder(new OrderProposal('AliExpress', '81', ProposalKind::Ordered, '2026-11-19', 'EUR', $line), [0], '');
        $none = (new ChangeListBuilder(self::target(self::firms())))->newOrder(new OrderProposal('PayPal', '82', ProposalKind::Ordered, '2026-11-19', 'EUR', $line), [0], '');

        self::assertSame('46727', end($found)['values']['105']);
        self::assertArrayNotHasKey('105', end($none)['values']);
        self::assertSame('PayPal 82 · LM317', end($none)['values']['101'], 'the title keeps the name the source used');
    }

    public function testTheComparisonComparesFirmsNotNames(): void
    {
        $proposal = new OrderProposal('ALIEXPRESS', '81', ProposalKind::Ordered, '2026-11-19', 'EUR', []);
        $same = new ExistingOrder(1, values: ['platform' => 'AliExpress'], platformFirms: self::firms());
        $empty = new ExistingOrder(2, values: ['platform' => ''], platformFirms: self::firms());
        $other = new ExistingOrder(3, values: ['platform' => 'eBay'], platformFirms: self::firms());

        self::assertArrayNotHasKey('platform', OrderComparison::between($proposal, $same));
        self::assertSame(['model' => null, 'source' => 'AliExpress', 'conflict' => false], OrderComparison::between($proposal, $empty)['platform']);
        self::assertTrue(OrderComparison::between($proposal, $other)['platform']['conflict']);
        self::assertArrayNotHasKey('platform', OrderComparison::between(new OrderProposal('PayPal', '81', ProposalKind::Ordered), $empty), 'a name that names no firm says nothing');

        $filled = (new ChangeListBuilder(self::target(self::firms())))->completed($empty, $proposal, ['platform']);
        self::assertSame([['op' => 'set_values', 'record' => '2', 'values' => ['105' => '46727']]], $filled);
    }

    private static function firms(): PlatformFirms
    {
        return new PlatformFirms([46727 => 'AliExpress', 46801 => 'eBay', 35197 => 'Mouser Electronics', 7 => 'Doppel', 8 => 'doppel', 9 => 'Conrad Electronic', 10 => 'Conrad Electronic SE']);
    }

    private static function target(?PlatformFirms $firms = null): TargetModel
    {
        return new TargetModel(10, 20, 99, 4234, 4236, [
            'title' => 101, 'origin' => 102, 'origin_note' => 103, 'order_number' => 104, 'platform' => 105,
            'merchant' => 106, 'ordered_at' => 107, 'total' => 111, 'lines' => 113,
        ], [
            'title' => 201, 'origin' => 202, 'quantity' => 203, 'article_number' => 204, 'unit_price' => 205,
            'total_price' => 206, 'origin_note' => 208,
        ], ['EUR' => 8571], [205, 206], platformFirms: $firms);
    }
}
