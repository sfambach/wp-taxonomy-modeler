<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Printing\LabelDocument;
use Taxmod\Core\Printing\LabelFormat;
use Taxmod\Core\Printing\LabelLayout;
use Taxmod\Core\Printing\ShortValue;

/**
 * D-1217: the SMD label — the value in its short code, the first value field the record has, the text in the middle.
 *
 * @see docs/NewConcept/90-decision-log.md D-1217, D-1236
 */
final class ShortValueTest extends TestCase
{
    #[Test]
    public function the_prefix_stands_where_the_comma_would(): void
    {
        self::assertSame('24k', ShortValue::of('24 kΩ'));
        self::assertSame('4k7', ShortValue::of('4,7 kΩ'));
        self::assertSame('4k7', ShortValue::of('4.70 kΩ'));
        self::assertSame('100R', ShortValue::of('100 Ω'));
        self::assertSame('0R22', ShortValue::of('0,22 Ω'));
        self::assertSame('1M', ShortValue::of('1 MΩ'));
        self::assertSame('100n', ShortValue::of('100 nF'));
        self::assertSame('4n7', ShortValue::of('4,7 nF'));
        self::assertSame('10µ', ShortValue::of('10 µF'));
        self::assertSame('22p', ShortValue::of('22 pF'));
        self::assertSame('4µ7', ShortValue::of('4,7 µH'));
    }

    #[Test]
    public function what_is_not_a_value_stays_as_it_is(): void
    {
        self::assertSame('0603', ShortValue::of('0603'));
        self::assertSame('±1 %', ShortValue::of('±1 %'));
        self::assertSame('12 V', ShortValue::of('12 V'));
    }

    #[Test]
    public function the_first_field_with_a_value_is_printed_short_and_centred(): void
    {
        $layout = LabelLayout::fromJson('[{"type":"text","field":"Widerstandswert|Kapazität","x":25,"y":0.5,"w":12,"h":5.5,"pt":13,"bold":true,"short":true,"center":true},'
            . '{"type":"text","field":"Bauform","x":25,"y":6.5,"w":12,"h":5,"pt":13,"center":true}]');
        self::assertSame(['Widerstandswert', 'Kapazität', 'Bauform'], $layout->fields());
        self::assertTrue($layout->elements[0]->short);
        self::assertTrue($layout->elements[0]->centred);

        $pdf = LabelDocument::renderLayout(new LabelFormat('x1smd', 37, 12, 12), $layout, [['Kapazität' => '100 nF', 'Bauform' => '0603']]);
        self::assertStringContainsString('(100n) Tj', $pdf);
        self::assertStringContainsString('(0603) Tj', $pdf);
        self::assertStringContainsString('"short":true', $layout->toJson());
    }

    #[Test]
    public function two_labels_on_one_with_a_cut_line_between(): void
    {
        $layout = LabelLayout::fromJson('[{"type":"text","field":"Widerstandswert","x":0.5,"y":3,"w":9,"h":5,"pt":11,"short":true},'
            . '{"type":"line","x":19.9,"y":0,"w":0.2,"h":12},'
            . '{"type":"text","field":"Widerstandswert","x":30,"y":3,"w":9.5,"h":5,"pt":11,"short":true,"right":true}]');
        self::assertSame(['Widerstandswert'], $layout->fields());
        self::assertTrue($layout->elements[2]->flushRight);
        self::assertSame('line', $layout->elements[1]->type);

        $pdf = LabelDocument::renderLayout(new LabelFormat('x1smd40', 40, 12, 12), $layout, [['Widerstandswert' => '30 kΩ']]);
        self::assertSame(2, substr_count($pdf, '(30k) Tj'));
        self::assertStringContainsString(' re', $pdf);
        self::assertStringContainsString('"right":true', $layout->toJson());
        self::assertStringNotContainsString('"field":""', $layout->toJson());
    }
}
