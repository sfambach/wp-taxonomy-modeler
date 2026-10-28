<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Import\Mail\KindFilter;
use Taxmod\Core\Import\Mail\MailMessage;
use Taxmod\Core\Import\Mail\OrderMailParser;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;

final class ImportMailKindFilterTest extends TestCase
{
    public function testAnUntickedKindBringsNothing(): void
    {
        $filtered = new KindFilter(self::reader(ProposalKind::Delivered), [ProposalKind::Delivered]);
        $proposal = $filtered->parse(new MailMessage(1, 'a@shop.de', 'Geliefert', null, '', ''));

        self::assertSame([ProposalKind::NoReceipt, 'A-1'], [$proposal->kind, $proposal->orderNumber]);
        self::assertSame('1.0.0-delivered', $filtered->version());
    }

    public function testATickedKindPassesUnchanged(): void
    {
        $filtered = new KindFilter(self::reader(ProposalKind::Shipped), [ProposalKind::Delivered]);

        self::assertSame(ProposalKind::Shipped, $filtered->parse(new MailMessage(1, 'a@shop.de', 'Versendet', null, '', ''))->kind);
        self::assertSame('shop', $filtered->key());
    }

    private static function reader(ProposalKind $kind): OrderMailParser
    {
        return new class ($kind) implements OrderMailParser {
            public function __construct(private readonly ProposalKind $kind)
            {
            }

            public function key(): string { return 'shop'; }
            public function shopName(): string { return 'Shop'; }
            public function recognises(MailMessage $mail): bool { return true; }
            public function parse(MailMessage $mail): OrderProposal { return new OrderProposal('Shop', 'A-1', $this->kind); }
            public function kinds(): array { return [ProposalKind::Shipped, ProposalKind::Delivered]; }
            public function version(): string { return '1.0.0'; }
        };
    }
}
