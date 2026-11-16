<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Import\Mail\MailMessage;
use Taxmod\Core\Import\Mail\ParcelNoticeMailParser;
use Taxmod\Core\Import\ProposalKind;

/** The shared frame of a mail connection: the domain rule and the parcel notice, written once. */
final class ShopMailParserTest extends TestCase
{
    private function carrier(): ParcelNoticeMailParser
    {
        return new class ('demo', 'Demo', ['demo.de'], '1.2.3') extends ParcelNoticeMailParser {
            protected function kind(MailMessage $mail): ProposalKind
            {
                return str_contains($mail->subject, 'zugestellt') ? ProposalKind::Delivered : (str_contains($mail->subject, 'unterwegs') ? ProposalKind::Shipped : ProposalKind::NoReceipt);
            }

            protected function parcelNumber(MailMessage $mail): ?string
            {
                return preg_match('/\d{12}/', $mail->text, $m) === 1 ? $m[0] : null;
            }
        };
    }

    private static function mail(string $from, string $subject, string $text = ''): MailMessage
    {
        return new MailMessage(1, $from, $subject, null, $text, '');
    }

    #[Test]
    public function it_knows_its_domain_and_subdomains_only(): void
    {
        $parser = $this->carrier();

        $this->assertTrue($parser->recognises(self::mail('noreply@demo.de', 'x')));
        $this->assertTrue($parser->recognises(self::mail('a@mail.demo.de', 'x')));
        $this->assertFalse($parser->recognises(self::mail('a@notdemo.de', 'x')));
        $this->assertSame(['demo', 'Demo', '1.2.3'], [$parser->key(), $parser->shopName(), $parser->version()]);
        $this->assertSame([ProposalKind::Shipped, ProposalKind::Delivered], $parser->kinds());
    }

    #[Test]
    public function a_notice_carries_the_parcel_number(): void
    {
        $proposal = $this->carrier()->parse(self::mail('a@demo.de', 'Paket zugestellt', 'Nr. 123456789012'));

        $this->assertSame(ProposalKind::Delivered, $proposal->kind);
        $this->assertSame('sendung:123456789012', $proposal->orderNumber);
        $this->assertSame(['123456789012'], $proposal->trackingNumbers);
        $this->assertTrue($proposal->numberMissing);
    }

    #[Test]
    public function without_kind_or_number_it_is_no_receipt(): void
    {
        $this->assertSame(ProposalKind::NoReceipt, $this->carrier()->parse(self::mail('a@demo.de', 'Werbung', '123456789012'))->kind);
        $this->assertSame(ProposalKind::NoReceipt, $this->carrier()->parse(self::mail('a@demo.de', 'Paket unterwegs'))->kind);
    }
}
