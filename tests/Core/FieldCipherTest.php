<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Exception\FieldDoesNotOpen;
use Taxmod\Core\Privacy\FieldCipher;

/** A confidential field's text is sealed and opened again, and nothing else passes for it (D-1514). */
final class FieldCipherTest extends TestCase
{
    private static function key(string $byte): string
    {
        return str_repeat($byte, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    }

    #[Test]
    public function a_sealed_text_opens_to_what_went_in_and_does_not_show_it(): void
    {
        $cipher = new FieldCipher(['k1' => self::key('a')]);
        $sealed = $cipher->seal('Erika Mustermann, Hauptstraße 5');

        self::assertStringStartsWith('enc1:k1:', $sealed);
        self::assertStringNotContainsString('Mustermann', $sealed);
        self::assertStringNotContainsString(' ', $sealed);
        self::assertSame('Erika Mustermann, Hauptstraße 5', $cipher->open($sealed));
    }

    #[Test]
    public function the_same_text_seals_differently_each_time(): void
    {
        $cipher = new FieldCipher(['k1' => self::key('a')]);

        self::assertNotSame($cipher->seal('x'), $cipher->seal('x'));
    }

    #[Test]
    public function sealing_twice_is_sealing_once_and_a_plain_text_opens_as_itself(): void
    {
        $cipher = new FieldCipher(['k1' => self::key('a')]);
        $sealed = $cipher->seal('x');

        self::assertSame($sealed, $cipher->seal($sealed));
        self::assertSame('written before', $cipher->open('written before'));
    }

    #[Test]
    public function another_key_refuses_and_names_a_missing_key_id(): void
    {
        $sealed = (new FieldCipher(['k1' => self::key('a')]))->seal('x');

        try {
            (new FieldCipher(['k1' => self::key('b')]))->open($sealed);
            self::fail('opened with a wrong key');
        } catch (FieldDoesNotOpen $e) {
            self::assertStringContainsString('does not open', $e->getMessage());
        }

        $this->expectException(FieldDoesNotOpen::class);
        $this->expectExceptionMessage('k1');
        (new FieldCipher(['k2' => self::key('a')]))->open($sealed);
    }

    #[Test]
    public function a_changed_value_is_refused(): void
    {
        $cipher = new FieldCipher(['k1' => self::key('a')]);
        $sealed = $cipher->seal('Hauptstraße 5');
        $raw    = base64_decode(substr($sealed, strlen('enc1:k1:')), true);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 1);

        $this->expectException(FieldDoesNotOpen::class);
        $cipher->open('enc1:k1:' . base64_encode($raw));
    }

    #[Test]
    public function an_old_key_still_opens_and_the_new_one_seals(): void
    {
        $old    = (new FieldCipher(['k1' => self::key('a')]))->seal('x');
        $cipher = new FieldCipher(['k2' => self::key('b'), 'k1' => self::key('a')]);

        self::assertSame('x', $cipher->open($old));
        self::assertTrue($cipher->needsResealing($old));
        self::assertStringStartsWith('enc1:k2:', $cipher->seal('x'));
        self::assertFalse($cipher->needsResealing($cipher->seal('x')));
    }

    #[Test]
    public function a_key_is_read_as_hex_or_base64_and_a_short_one_refused(): void
    {
        $text = FieldCipher::newKeyText();

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $text);
        self::assertSame(hex2bin($text), FieldCipher::keyFromText($text));
        self::assertSame(self::key('a'), FieldCipher::keyFromText(base64_encode(self::key('a'))));

        $this->expectException(FieldDoesNotOpen::class);
        FieldCipher::keyFromText('abc');
    }

    #[Test]
    public function no_key_or_a_bad_key_id_is_refused(): void
    {
        try {
            new FieldCipher([]);
            self::fail('built without a key');
        } catch (FieldDoesNotOpen) {
            self::assertTrue(true);
        }

        $this->expectException(FieldDoesNotOpen::class);
        new FieldCipher(['K 1' => self::key('a')]);
    }
}
