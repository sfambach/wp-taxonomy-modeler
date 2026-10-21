<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;
use Taxmod\Core\Import\ProposalLine;
use Taxmod\Core\Import\ProposalGroup;
use Taxmod\Core\Import\InboxGrouping;
use Taxmod\Core\Import\LooseMatch;

/**
 * Merging and loose matching, carried over from tax_mail with its cases (§3.8).
 *
 * @see docs/pakete/import/konzept.md
 */
final class ImportGroupTest extends TestCase
{
    public function testConfirmationItemsWinAndTrackingAddsUp(): void
    {
        $shipped = new OrderProposal('Ebay', '13-15309-77264', ProposalKind::Shipped, '2026-10-20', 'EUR',
            [new ProposalLine('Universal Ladegerät', 1.0, null, '157915720994')], null, null, null, null, '2026-10-20', ['DHL123456789']);
        $confirmed = new OrderProposal('Ebay', '13-15309-77264', ProposalKind::Ordered, '2026-10-18', 'EUR',
            [new ProposalLine('Universal Ladegerät Netzteil', 1.0, '14.99', '157915720994')], '0', '14.99', 'seller');

        $merged = ProposalGroup::merge([$shipped, $confirmed]);

        self::assertSame(ProposalKind::Ordered, $merged->kind);
        self::assertSame('2026-10-18', $merged->orderDate);
        self::assertSame('14.99', $merged->lines[0]->unitPrice);
        self::assertSame(['14.99', 'seller', '2026-10-20', ['DHL123456789']], [$merged->total, $merged->counterparty, $merged->shippedDate, $merged->trackingNumbers]);
    }

    public function testWonAuctionTakesTheRealNumberAndGivesItsPrice(): void
    {
        $won = new OrderProposal('Ebay', 'ohne-Nr:allerlei1970:Konvolut Elektro PC', ProposalKind::Invoice, '2024-11-20', 'EUR',
            [new ProposalLine('Konvolut Elektro PC…', 1.0, '7.5')], null, null, 'allerlei1970', numberMissing: true);
        $shipped = new OrderProposal('Ebay', '05-12345-67890', ProposalKind::Shipped, '2024-11-22', 'EUR',
            [new ProposalLine('Konvolut Elektro PC Teile', 1.0, null, '123')], null, null, 'allerlei1970', null, '2024-11-22', ['ABC12345678']);

        $merged = ProposalGroup::merge([$won, $shipped]);

        self::assertSame(['05-12345-67890', '7.5', ['ABC12345678']], [$merged->orderNumber, $merged->lines[0]->unitPrice, $merged->trackingNumbers]);
        self::assertTrue(LooseMatch::matches($won, 'Allerlei1970', '2024-11-25', ''));
        self::assertFalse(LooseMatch::matches($won, 'allerlei1970', '2024-12-25', ''));
        self::assertTrue(LooseMatch::matches($won, 'other', '2024-11-21', 'eBay 05-1 · Konvolut Elektro PC Teile'));
    }

    public function testInvoiceWithArticleNumberWinsOverAcceptedOffer(): void
    {
        $offer = new OrderProposal('Ebay', 'ohne-Nr:etzala:Terratec TT1816-N v1...', ProposalKind::Invoice, '2025-02-23', 'EUR',
            [new ProposalLine('Terratec TT1816-N v1...', 1.0, '16')], null, null, 'etzala', numberMissing: true);
        $invoice = new OrderProposal('Ebay', 'ohne-Nr:315973194490', ProposalKind::Invoice, '2025-02-24', 'EUR',
            [new ProposalLine('Terratec TT1816-N v1.0 16 BIT ISA PnP Soundkarte Retro', 1.0, '16', '315973194490')], null, '20.95', numberMissing: true);

        $merged = ProposalGroup::merge([$offer, $invoice]);

        self::assertTrue(LooseMatch::matches($offer, null, $invoice->orderDate, $invoice->lines[0]->title));
        self::assertSame(['315973194490', '20.95', 'etzala'], [$merged->lines[0]->articleNumber, $merged->total, $merged->counterparty]);
    }

    public function testOrderWithoutDateNeedsSellerAndTitle(): void
    {
        $won = new OrderProposal('Ebay', 'ohne-Nr:xenoux:Creative Sound Blast...', ProposalKind::Invoice, '2025-09-20', 'EUR',
            [new ProposalLine('Creative Sound Blast...', 1.0, '85')], null, null, 'xenoux', numberMissing: true);

        self::assertTrue(LooseMatch::matches($won, 'xenoux', null, 'eBay 18-13590-39252 · Creative Sound Blaster 2.0 CT1350B'));
        self::assertFalse(LooseMatch::matches($won, 'someone', null, 'eBay 18-13590-39252 · Creative Sound Blaster 2.0 CT1350B'));
        self::assertFalse(LooseMatch::matches($won, 'xenoux', null, 'eBay 1 · Gravis Ultrasound'));
    }

    public function testWithoutConfirmationTheItemsAreUnited(): void
    {
        $a = new OrderProposal('AliExpress', '1', ProposalKind::Other, '2026-10-20', 'EUR', [new ProposalLine('WCH-LinkE', 1.0)], null, '9.76');
        $b = new OrderProposal('AliExpress', '1', ProposalKind::Other, '2026-10-20', 'EUR', [new ProposalLine('WCH-LinkE', 1.0)], null, '9.76');

        $merged = ProposalGroup::merge([$a, $b]);

        self::assertCount(1, $merged->lines);
        self::assertSame('9.76', $merged->total);
    }

    public function testALooseProposalJoinsTheOpenOrderItBelongsTo(): void
    {
        $won = new OrderProposal('Ebay', 'ohne-Nr:allerlei1970', ProposalKind::Invoice, '2024-11-20', 'EUR',
            [new ProposalLine('Konvolut Elektro PC…', 1.0, '7.5')], null, null, 'allerlei1970', numberMissing: true);
        $shipped = new OrderProposal('Ebay', '05-12345-67890', ProposalKind::Shipped, '2024-11-22', 'EUR',
            [new ProposalLine('Konvolut Elektro PC Teile', 1.0)], null, null, 'allerlei1970');
        $other = new OrderProposal('Reichelt', '9', ProposalKind::Ordered, '2024-11-22', 'EUR', [new ProposalLine('LM317', 1.0)]);

        self::assertSame([[2, 1], [3]], InboxGrouping::group([1 => $won, 2 => $shipped, 3 => $other]));
    }
}
