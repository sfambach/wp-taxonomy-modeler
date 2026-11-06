<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Printing\FilledLabel;
use Taxmod\Core\Printing\LabelPrinter;

/**
 * D-1190, D-1191: the printer carries every setting; a label is only its size and layout, and prints on the printer of its roll.
 */
final class LabelPrinterTest extends TestCase
{
    private const LAYOUT = [['type' => 'qr', 'field' => 'code', 'x' => 2, 'y' => 2, 'w' => 17, 'h' => 17]];

    private const BROTHER = ['queue' => 'QL-810W_take', 'roll' => 62, 'turn' => '1', 'turn_options' => 'orientation-requested=4',
        'sizes' => "62x50=BrL053E03262D56\n62x30=BrL053E01E3D0F6\n100x62=62x100", 'name' => 'QL-810W'];

    private const PHOMEMO = ['queue' => 'Phomemo_M220L', 'roll' => 40, 'turn' => '0', 'sizes' => '*=w{B}h{H}', 'offset' => '21', 'line' => 38, 'name' => 'Phomemo M22L'];

    private static function label(?array $printer, float $w = 40, float $h = 30, float $roll = 40): FilledLabel
    {
        return FilledLabel::fromJson((string) json_encode([
            'format'  => ['key' => 'x', 'w' => $w, 'h' => $h, 'roll' => $roll],
            'layout'  => self::LAYOUT,
            'values'  => ['code' => 'M-49007'],
            'printer' => $printer,
        ]));
    }

    #[Test]
    public function options_are_read_as_a_person_writes_them(): void
    {
        self::assertSame(['PageSize' => 'w40h30', 'orientation-requested' => '4', 'fitplot' => ''], LabelPrinter::parseOptions("PageSize=w40h30\norientation-requested=4  fitplot"));
    }

    #[Test]
    public function the_brother_turns_a_label_as_wide_as_its_roll_and_names_its_size(): void
    {
        $holder = self::label(self::BROTHER, 62, 50, 62);
        self::assertTrue($holder->format->turnedOnRoll());
        self::assertSame(['PageSize' => 'BrL053E03262D56', 'orientation-requested' => '4'], $holder->printer->optionsFor($holder->format));

        $box = self::label(self::BROTHER, 100, 62, 62);
        self::assertFalse($box->format->turnedOnRoll());
        self::assertSame(['PageSize' => '62x100'], $box->printer->optionsFor($box->format), 'not turned: no orientation');
    }

    #[Test]
    public function the_phomemo_takes_the_label_as_it_is_and_names_sizes_by_millimetres(): void
    {
        $label = self::label(self::PHOMEMO);
        self::assertFalse($label->format->turnedOnRoll());
        self::assertSame(['PageSize' => 'w40h30'], $label->printer->optionsFor($label->format));
        self::assertStringContainsString('/MediaBox [0 0 113.386 85.039]', $label->render());
    }

    #[Test]
    public function without_a_printer_the_roll_decides_as_before(): void
    {
        self::assertTrue(self::label(null)->format->turnedOnRoll());
        self::assertNull(self::label(null)->printer);
    }

    #[Test]
    public function the_roll_loaded_in_the_printer_decides_over_the_roll_the_label_names(): void
    {
        $label = self::label(self::BROTHER, 62, 30, 102);
        self::assertSame(62.0, $label->format->rollMm, 'D-1192');
        self::assertTrue($label->format->turnedOnRoll());
    }

    #[Test]
    public function the_printer_travels_with_the_job(): void
    {
        $again = FilledLabel::fromJson(self::label(self::PHOMEMO)->toJson());
        self::assertSame('Phomemo_M220L', $again->printer?->queue);
        self::assertSame(['*' => 'w{B}h{H}'], $again->printer->sizes);
        self::assertSame(21.0, $again->printer->offsetMm);
        self::assertFalse($again->format->turnedOnRoll());
    }

    #[Test]
    public function a_printer_that_starts_its_lines_late_gets_the_drawing_moved_back_and_wrapped(): void
    {
        // His Phomemo M22L, measured with a ruler label on 2026-11-06: lines start 21 mm along a 38 mm line.
        $pdf = self::label(self::PHOMEMO)->render();
        self::assertStringContainsString("q 1 0 0 1 -59.528 0 cm\n", $pdf);
        self::assertStringContainsString("q 1 0 0 1 48.189 0 cm\n", $pdf);
        self::assertSame(2, substr_count($pdf, "Q\n"));
        self::assertStringNotContainsString(' cm', self::label(['queue' => 'Q', 'turn' => '0'])->render(), 'no offset, no move');
    }

    #[Test]
    public function a_queue_that_is_not_a_plain_name_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::label(['queue' => '-h evil.host']);
    }

    #[Test]
    public function an_option_that_is_not_a_plain_name_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::label(['queue' => 'Q', 'options' => 'PageSize=a/b']);
    }

    #[Test]
    public function a_size_name_that_is_not_plain_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::label(['queue' => 'Q', 'sizes' => '40x30=w/../x']);
    }
}
