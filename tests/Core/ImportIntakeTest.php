<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Import\DutyRole;
use Taxmod\Core\Import\DutyTable;
use Taxmod\Core\Import\ExistingOrder;
use Taxmod\Core\Import\Intake;
use Taxmod\Core\Import\IntakeVerdict;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalGroup;
use Taxmod\Core\Import\ProposalKind;
use Taxmod\Core\Import\ProposalLine;

/**
 * The inbox's decisions: the duty table (D-953 S4), duplicates by platform and order number, the group of one order.
 *
 * @see docs/pakete/import/konzept.md §3.4
 */
final class ImportIntakeTest extends TestCase
{
    private static function order(string $platform, ProposalKind $kind = ProposalKind::Ordered, array $tracking = []): OrderProposal
    {
        return new OrderProposal($platform, 'A-1', $kind, lines: [new ProposalLine('LM317', 2.0, '0.5')], trackingNumbers: $tracking);
    }

    #[Test]
    public function the_responsible_source_brings_orders_the_others_do_not(): void
    {
        $duties = DutyTable::initial();

        self::assertTrue($duties->allows('ebay', 'eBay', DutyRole::Orders, ['ebay', 'mail']));
        self::assertFalse($duties->allows('mail', 'ebay ', DutyRole::Orders, ['ebay', 'mail']));
        self::assertTrue($duties->allows('mail', 'eBay', DutyRole::Status, ['ebay', 'mail']));
        self::assertTrue($duties->allows('mail', 'Reichelt', DutyRole::Orders, ['ebay', 'mail']), 'a platform without a row is open');
    }

    #[Test]
    public function an_inactive_responsible_source_hands_its_role_on(): void
    {
        self::assertTrue(DutyTable::initial()->allows('mail', 'eBay', DutyRole::Orders, ['mail']));
    }

    #[Test]
    public function unknown_roles_and_empty_sources_are_dropped(): void
    {
        $duties = new DutyTable(['X' => ['orders' => ' ', 'bogus' => 'mail', 'status' => 'mail']]);

        self::assertSame(['x' => ['status' => 'mail']], $duties->toArray());
    }

    #[Test]
    public function a_new_order_is_new_only_for_the_responsible_source(): void
    {
        $intake = new Intake(DutyTable::initial(), ['ebay', 'mail']);

        self::assertSame(IntakeVerdict::NewOrder, $intake->decide('ebay', self::order('eBay'), null));
        self::assertSame(IntakeVerdict::NotResponsible, $intake->decide('mail', self::order('eBay'), null));
        self::assertTrue($intake->accepts('mail', self::order('eBay')), 'mail may still bring the status');
    }

    #[Test]
    public function an_order_in_the_model_is_an_update_only_when_it_brings_something(): void
    {
        $intake = new Intake(DutyTable::initial(), ['ebay', 'mail']);
        $existing = new ExistingOrder(7, ['T1']);

        self::assertSame(IntakeVerdict::Nothing, $intake->decide('mail', self::order('Reichelt', ProposalKind::Shipped, ['T1']), $existing));
        self::assertSame(IntakeVerdict::Update, $intake->decide('mail', self::order('Reichelt', ProposalKind::Shipped, ['T2']), $existing));
        self::assertSame(IntakeVerdict::Update, $intake->decide('mail', self::order('eBay', ProposalKind::Delivered), $existing));
        self::assertSame(IntakeVerdict::Nothing, $intake->decide('mail', self::order('eBay', ProposalKind::Delivered), new ExistingOrder(7, [], '2026-10-01')));
    }

    #[Test]
    public function a_status_without_an_order_in_the_model_and_without_items_is_nothing(): void
    {
        $intake = new Intake(DutyTable::initial(), ['mail']);

        self::assertSame(IntakeVerdict::Nothing, $intake->decide('mail', new OrderProposal('Reichelt', 'A-1', ProposalKind::Shipped, trackingNumbers: ['T']), null));
    }

    #[Test]
    public function a_group_keeps_the_priced_items_and_adds_up_tracking(): void
    {
        $shipped = new OrderProposal('JLCPCB', 'W1', ProposalKind::Shipped, lines: [new ProposalLine('Board', null)], trackingNumbers: ['T1'], source: 'mail');
        $priced = new OrderProposal('JLCPCB', 'W1', ProposalKind::Ordered, '2026-10-01', 'USD', [new ProposalLine('Board', 5.0, '2.00')], '8.00', source: 'jlcpcb');
        $merged = ProposalGroup::merge([$shipped, $priced]);

        self::assertSame('2.00', $merged->lines[0]->unitPrice);
        self::assertSame('USD', $merged->currency);
        self::assertSame(['T1'], $merged->trackingNumbers);
        self::assertSame('8.00', $merged->shippingCost);
        self::assertSame(ProposalKind::Ordered, $merged->kind);
    }
}
