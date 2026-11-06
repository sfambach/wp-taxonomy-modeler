<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Printing\LabelContent;
use Taxmod\Core\Printing\LabelDocument;
use Taxmod\Core\Printing\LabelFormat;
use Taxmod\Core\Printing\LabelPrefix;
use Taxmod\Core\Printing\PdfDocument;
use Taxmod\Core\Printing\QrCode;
use Taxmod\Core\Printing\ReedSolomon;

/**
 * Labels with QR code (D-1032) and the test print (D-1096) — encoder, PDF writer and layout without WordPress.
 *
 * ⚠️ That a scanner reads the codes was checked outside this run, with a real decoder against
 * versions 1 to 10 and against the rendered label PDFs; here the structure is guarded.
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class PrintingTest extends TestCase
{
    #[Test]
    public function the_prefixes_are_fixed(): void
    {
        self::assertSame('O-12', LabelPrefix::Place->code(12));
        self::assertSame('M-12', LabelPrefix::Material->code(12));
        self::assertSame('E-12', LabelPrefix::Copy->code(12));
        self::assertSame('K-12', LabelPrefix::Box->code(12));
    }

    #[Test]
    public function a_label_code_fits_the_smallest_version_and_long_text_grows_it(): void
    {
        self::assertSame(1, QrCode::encode('O-149000110146')->version);
        self::assertSame(21, QrCode::encode('O-1')->size());
        self::assertSame(10, QrCode::encode(str_repeat('x', 200))->version);
        self::assertSame(57, QrCode::encode(str_repeat('x', 200))->size());
    }

    #[Test]
    public function text_beyond_version_ten_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrCode::encode(str_repeat('x', 300));
    }

    #[Test]
    public function the_three_finder_patterns_and_the_dark_module_stand(): void
    {
        $qr = QrCode::encode('K-42');
        $last = $qr->size() - 1;
        foreach ([[0, 0], [$last - 6, 0], [0, $last - 6]] as [$x, $y]) {
            self::assertTrue($qr->isDark($x, $y));
            self::assertTrue($qr->isDark($x + 6, $y + 6));
            self::assertFalse($qr->isDark($x + 1, $y + 1));
            self::assertTrue($qr->isDark($x + 3, $y + 3));
        }
        self::assertTrue($qr->isDark(8, $qr->size() - 8));
    }

    #[Test]
    public function the_same_text_gives_the_same_code(): void
    {
        $a = QrCode::encode('E-7');
        $b = QrCode::encode('E-7');
        for ($y = 0; $y < $a->size(); $y++) {
            for ($x = 0; $x < $a->size(); $x++) {
                self::assertSame($a->isDark($x, $y), $b->isDark($x, $y));
            }
        }
    }

    #[Test]
    public function data_plus_error_correction_divides_by_the_generator(): void
    {
        $data = [32, 91, 11, 120, 209, 114, 220, 77, 67, 64, 236, 17, 236, 17, 236, 17];
        $generator = ReedSolomon::generator(10);
        $ec = ReedSolomon::remainder($data, $generator);
        self::assertCount(10, $ec);
        self::assertSame(array_fill(0, 10, 0), ReedSolomon::remainder(array_merge($data, $ec), $generator));
    }

    #[Test]
    public function the_pdf_has_one_page_per_label_and_a_correct_cross_reference(): void
    {
        $pdf = LabelDocument::render(LabelFormat::byKey('roll62'), [LabelContent::sample(), LabelContent::of(LabelPrefix::Box, 42, 'Kiste 7')]);
        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringContainsString('/Count 2', $pdf);
        self::assertStringContainsString('/MediaBox [0 0 283.465 175.748]', $pdf);
        self::assertStringContainsString('(K-42) Tj', $pdf);
        self::assertSame(1, preg_match('/startxref\n(\d+)\n/', $pdf, $start));
        $xref = substr($pdf, (int) $start[1]);
        self::assertStringStartsWith('xref', $xref);
        preg_match_all('/^(\d{10}) 00000 n $/m', $xref, $offsets);
        foreach ($offsets[1] as $i => $offset) {
            self::assertStringStartsWith(($i + 1) . ' 0 obj', substr($pdf, (int) $offset));
        }
    }

    #[Test]
    public function umlauts_are_written_in_win_ansi(): void
    {
        $pdf = new PdfDocument();
        $pdf->addPage(50, 20);
        $pdf->text(1, 10, 'Bücher (Regal)', 10);
        self::assertStringContainsString("(B\xFCcher \\(Regal\\)) Tj", $pdf->render());
    }

    #[Test]
    public function signs_win_ansi_lacks_print_as_their_nearest_not_as_a_question_mark(): void
    {
        $pdf = new PdfDocument();
        $pdf->addPage(50, 20);
        $pdf->text(1, 10, 'LCD · 2,4″ · −5 V', 10);
        $rendered = $pdf->render();
        self::assertStringContainsString("(LCD \xB7 2,4\" \xB7 -5 V) Tj", $rendered);
        self::assertStringNotContainsString('?', substr($rendered, (int) strpos($rendered, 'BT')));
    }

    #[Test]
    public function the_ohm_sign_comes_from_the_symbol_font(): void
    {
        $pdf = new PdfDocument();
        $pdf->addPage(50, 20);
        $pdf->text(1, 10, '10 kΩ', 10);
        $rendered = $pdf->render();
        self::assertStringContainsString('/F1 10 Tf (10 k) Tj /F3 10 Tf (W) Tj', $rendered);
        self::assertStringContainsString('/BaseFont /Symbol', $rendered);
        self::assertEqualsWithDelta(PdfDocument::textWidthMm('10 k', 10) + PdfDocument::pointsToMm(7.68), PdfDocument::textWidthMm('10 kΩ', 10), 0.001);
    }

    #[Test]
    public function a_title_too_long_for_the_label_is_cut_not_overflowing(): void
    {
        $pdf = LabelDocument::render(LabelFormat::byKey('roll62'), [new LabelContent('O-1', str_repeat('Überlang', 40))]);
        self::assertStringContainsString("\x85) Tj", $pdf);
    }

    #[Test]
    public function the_holder_label_puts_the_title_across_the_top(): void
    {
        $pdf = LabelDocument::render(LabelFormat::byKey('holder62'), [LabelContent::of(LabelPrefix::Place, 4711, 'R2-F3', 'Keller')]);
        self::assertStringContainsString('/MediaBox [0 0 141.732 175.748]', $pdf, 'turned: 62 mm across the roll, 50 mm along it');
        self::assertStringContainsString("0 1 -1 0 141.732 0 cm", $pdf);
        self::assertSame(1, preg_match('/BT ([\d.]+) [\d.]+ Td \/F2 [\d.]+ Tf \(R2-F3\) Tj/', $pdf, $title));
        self::assertEqualsWithDelta(8.504, (float) $title[1], 0.01, 'the title starts at the left margin, not beside the QR code');
    }

    #[Test]
    public function the_material_label_is_62_by_30_with_the_qr_code_beside_the_title(): void
    {
        $pdf = LabelDocument::render(LabelFormat::byKey('material62'), [LabelContent::of(LabelPrefix::Material, 149000112345, 'Widerstand 10 kΩ', '0603 · 1 %')]);
        self::assertStringContainsString('/MediaBox [0 0 85.039 175.748]', $pdf);
        self::assertSame(1, preg_match('/BT ([\d.]+) [\d.]+ Td \/F2 [\d.]+ Tf \(Widerstand\) Tj/', $pdf, $title));
        self::assertGreaterThan(40.0, (float) $title[1], 'the title starts right of the QR code');
    }

    #[Test]
    public function only_a_label_as_wide_as_the_roll_is_turned(): void
    {
        self::assertFalse(LabelFormat::byKey('roll62')->turnedOnRoll());
        self::assertTrue(LabelFormat::byKey('holder62')->turnedOnRoll());
        self::assertTrue(LabelFormat::byKey('material62')->turnedOnRoll());
        self::assertStringNotContainsString(' cm', LabelDocument::render(LabelFormat::byKey('roll62'), [LabelContent::sample()]));
    }

    #[Test]
    public function cancel_and_print_again_undo_each_other(): void
    {
        self::assertTrue(\Taxmod\Core\Printing\PrintJobStatus::Queued->canBeCancelled());
        self::assertTrue(\Taxmod\Core\Printing\PrintJobStatus::Cancelled->canBeQueuedAgain());
        self::assertFalse(\Taxmod\Core\Printing\PrintJobStatus::Claimed->canBeCancelled(), 'a fetched job is on its way to the printer');
        self::assertTrue(\Taxmod\Core\Printing\PrintJobStatus::Printed->canBeQueuedAgain());
        self::assertTrue(\Taxmod\Core\Printing\PrintJobStatus::Claimed->acceptsResult());
        self::assertFalse(\Taxmod\Core\Printing\PrintJobStatus::Printed->acceptsResult());
    }

    #[Test]
    public function only_the_token_itself_matches_its_hash(): void
    {
        $token = \Taxmod\Core\Printing\PrintServiceToken::generate();
        $hash = \Taxmod\Core\Printing\PrintServiceToken::hash($token);
        self::assertSame(48, strlen($token));
        self::assertTrue(\Taxmod\Core\Printing\PrintServiceToken::matches($token, $hash));
        self::assertFalse(\Taxmod\Core\Printing\PrintServiceToken::matches($token . 'x', $hash));
        self::assertFalse(\Taxmod\Core\Printing\PrintServiceToken::matches('', ''));
    }

    #[Test]
    public function the_code_keeps_clear_of_the_bottom_edge(): void
    {
        $pdf = LabelDocument::render(LabelFormat::byKey('material62'), [LabelContent::of(LabelPrefix::Material, 4711, 'NE555', 'DIP-8')]);
        self::assertSame(1, preg_match('/BT [\d.]+ ([\d.]+) Td \/F1 [\d.]+ Tf \(M-4711\) Tj/', $pdf, $code));
        self::assertEqualsWithDelta(4.5 * 72 / 25.4, (float) $code[1], 0.01, 'the baseline 4.5 mm above the edge (D-1119)');
    }

    #[Test]
    public function an_unknown_format_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LabelFormat::byKey('a4');
    }
}
