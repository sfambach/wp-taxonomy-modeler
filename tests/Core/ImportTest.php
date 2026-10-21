<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Exception\SecretDoesNotOpen;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;
use Taxmod\Core\Import\ProposalLine;
use Taxmod\Core\Import\SecretBox;

/**
 * The shared part of the import modules: the vault (D-951) and the proposal format (D-952, D-953).
 *
 * @see docs/pakete/import/konzept.md
 */
final class ImportTest extends TestCase
{
    #[Test]
    public function a_sealed_secret_opens_again_and_is_not_readable(): void
    {
        $box = new SecretBox('salt', 'mail');
        $sealed = $box->seal('hunter2');

        self::assertStringNotContainsString('hunter2', $sealed);
        self::assertTrue(SecretBox::isSealed($sealed));
        self::assertSame('hunter2', $box->open($sealed));
    }

    #[Test]
    public function another_salt_or_context_does_not_open_it(): void
    {
        $sealed = (new SecretBox('salt', 'mail'))->seal('hunter2');

        foreach ([new SecretBox('other', 'mail'), new SecretBox('salt', 'ebay')] as $box) {
            try {
                $box->open($sealed);
                self::fail('opened with a different key');
            } catch (SecretDoesNotOpen) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function what_tax_mail_and_tax_ebay_sealed_before_still_opens(): void
    {
        $legacy = static function (string $context, string $plain, string $prefix): string {
            $key = sodium_crypto_generichash($context . '|salt', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

            return $prefix . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
        };

        self::assertSame('imap', (new SecretBox('salt', 'taxmail-secret'))->open($legacy('taxmail-secret', 'imap', 'sb1:')));
        self::assertSame('token', (new SecretBox('salt', 'taxebay-token'))->open($legacy('taxebay-token', 'token', '')));
    }

    #[Test]
    public function a_damaged_secret_is_refused(): void
    {
        $this->expectException(SecretDoesNotOpen::class);
        (new SecretBox('salt', 'mail'))->open('sb1:not-base64!');
    }

    #[Test]
    public function no_key_material_is_refused(): void
    {
        $this->expectException(SecretDoesNotOpen::class);
        new SecretBox('', 'mail');
    }

    #[Test]
    public function a_proposal_survives_the_round_trip(): void
    {
        $proposal = new OrderProposal(
            'eBay', '12-34', ProposalKind::Sold, '2026-10-21', 'EUR',
            [new ProposalLine('ISA card', 2.0, '10.50', '1234')],
            '4.99', '25.99', 'buyer', '0.00', '2026-10-22', ['TRK1'], 'ebay', 'order-1',
        );

        self::assertEquals($proposal, OrderProposal::fromArray(json_decode((string) json_encode($proposal->toArray()), true)));
    }

    #[Test]
    public function an_array_stored_before_source_and_external_id_still_reads(): void
    {
        $old = ['platform' => 'Reichelt', 'order_number' => '42', 'kind' => 'ordered', 'lines' => [['title' => 'R 10k', 'quantity' => 10]]];
        $proposal = OrderProposal::fromArray($old);

        self::assertNull($proposal->source);
        self::assertNull($proposal->externalId);
        self::assertSame(10.0, $proposal->lines[0]->quantity);
    }

    #[Test]
    public function duplicates_are_found_by_platform_and_order_number(): void
    {
        $a = new OrderProposal('eBay', '12-34', ProposalKind::Ordered, source: 'mail');
        $b = new OrderProposal(' ebay ', '12-34', ProposalKind::Shipped, source: 'ebay');

        self::assertSame($a->duplicateKey(), $b->duplicateKey());
        self::assertNotSame($a->duplicateKey(), (new OrderProposal('Reichelt', '12-34', ProposalKind::Ordered))->duplicateKey());
    }
}
