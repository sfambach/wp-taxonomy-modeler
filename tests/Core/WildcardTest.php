<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Listing\Wildcard;

/** D-1378: what a keyboard cannot type is not needed to find it. */
final class WildcardTest extends TestCase
{
    #[Test]
    public function a_unit_is_found_however_it_is_typed(): void
    {
        foreach (['10 uf', '10uF', '10 UF', '10 µF', '10μf'] as $term) {
            self::assertTrue(Wildcard::matches('Elko · 10 µF · 16 V', $term), $term);
        }

        self::assertTrue(Wildcard::matches('Widerstand 4,7 kΩ', '4.7k'));
        self::assertTrue(Wildcard::matches('Widerstand 4,7 kΩ', '4,7 kohm'));
        self::assertTrue(Wildcard::matches('Kabel · USB-A', 'kabel*usb'), 'the star of D-1357 still holds');
        self::assertFalse(Wildcard::matches('Kondensator 100 nF', '10uf'), 'a number is not cut');
        self::assertFalse(Wildcard::matches('Elko · 10 µF', '10 mf'));
    }

    #[Test]
    public function sql_gets_every_spelling_a_stored_text_may_have(): void
    {
        $spellings = Wildcard::spellings('10 uf');

        self::assertSame('10 uf', $spellings[0], 'the piece itself first');

        foreach (['10uf', '10 µf', '10µf', '10 μf'] as $one) {
            self::assertContains($one, $spellings);
        }

        self::assertContains('4,7 kΩ', Wildcard::spellings('4.7kohm'));
        self::assertSame(['usb'], Wildcard::spellings('usb'), 'a «u» not after a number stays as it is');
        self::assertLessThanOrEqual(48 + 1, count(Wildcard::spellings('1u 2u 3u 4u 5u 6u')));
    }
}
