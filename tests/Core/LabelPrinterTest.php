<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Printing\FilledLabel;
use Taxmod\Core\Printing\LabelPrinter;

/**
 * D-1190: a label names its printer; the job carries queue and options, and the printer decides whether the label is turned.
 */
final class LabelPrinterTest extends TestCase
{
    private const LAYOUT = [['type' => 'qr', 'field' => 'code', 'x' => 2, 'y' => 2, 'w' => 17, 'h' => 17]];

    private static function label(?array $printer = null, array $format = []): FilledLabel
    {
        return FilledLabel::fromJson((string) json_encode([
            'format'  => $format + ['key' => 'phomemo40x30', 'w' => 40, 'h' => 30, 'roll' => 40],
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
    public function a_label_as_wide_as_its_roll_is_turned_unless_its_printer_says_no(): void
    {
        self::assertTrue(self::label()->format->turnedOnRoll(), 'without a printer the roll decides, as before');
        $phomemo = self::label(['queue' => 'Phomemo_M220L', 'options' => 'PageSize=w40h30', 'turn' => '0', 'name' => 'Phomemo M22L']);
        self::assertFalse($phomemo->format->turnedOnRoll());
        self::assertStringContainsString('/MediaBox [0 0 113.386 85.039]', $phomemo->render());
    }

    #[Test]
    public function the_printer_travels_with_the_job(): void
    {
        $again = FilledLabel::fromJson(self::label(['queue' => 'Phomemo_M220L', 'options' => ['PageSize' => 'w40h30'], 'turn' => false])->toJson());
        self::assertSame('Phomemo_M220L', $again->printer?->queue);
        self::assertSame(['PageSize' => 'w40h30'], $again->printer->options);
        self::assertFalse($again->format->turnedOnRoll());
    }

    #[Test]
    public function a_printer_that_starts_its_lines_late_gets_the_drawing_moved_back_and_wrapped(): void
    {
        // His Phomemo M22L, measured with a ruler label on 2026-11-06: lines start 21 mm along a 38 mm line.
        $pdf = self::label(['queue' => 'Phomemo_M220L', 'turn' => false, 'offset' => '21', 'line' => 38])->render();
        self::assertStringContainsString("q 1 0 0 1 -59.528 0 cm\n", $pdf);
        self::assertStringContainsString("q 1 0 0 1 48.189 0 cm\n", $pdf);
        self::assertSame(2, substr_count($pdf, "Q\n"));
        self::assertStringNotContainsString(' cm', self::label(['queue' => 'Q', 'turn' => false])->render(), 'no offset, no move');
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
}
