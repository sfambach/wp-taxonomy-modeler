<?php declare(strict_types=1);

namespace Taxmod\Core\Printing;

/**
 * Lays labels out as a PDF, one page per label: QR code left, code, title and second line right.
 *
 * ```mermaid
 * flowchart LR
 *   C["LabelContent"] --> L["this · layout"]
 *   F["LabelFormat"] --> L
 *   L --> Q["QrCode"]
 *   L --> P["PdfDocument"] --> B["PDF bytes"]
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md D-1032
 */
final class LabelDocument
{
    private const MARGIN_MM = 3.0;
    /** The code's baseline sits this much above the bottom margin: at 3 mm the QL-810 cut its foot (his test print 2026-11-03, D-1119). */
    private const CODE_BOTTOM_MM = 4.5;
    private const GAP_MM = 3.0;
    private const QUIET_MODULES = 4;
    private const TITLE_MAX_PT = 28.0;
    private const SUBTITLE_MAX_PT = 14.0;
    private const CODE_PT = 12.0;
    private const MIN_PT = 7.0;
    private const QR_SHARE = 0.45;
    /** Below this width-to-height ratio the title goes across the top and the QR code below it. */
    private const WIDE_RATIO = 1.5;
    private const TITLE_LINES = 2;
    private const SUBTITLE_LINES = 4;
    /** QR rows overlap by this much, so no hairline seams show between them. */
    private const SEAM_MM = 0.02;

    /**
     * @param list<LabelContent> $labels
     */
    public static function render(LabelFormat $format, array $labels): string
    {
        $pdf = new PdfDocument();
        foreach ($labels as $label) {
            $pdf->addPage($format->widthMm, $format->heightMm, $format->turnedOnRoll());
            self::drawLabel($pdf, $format, $label);
        }
        return $pdf->render();
    }

    private static function drawLabel(PdfDocument $pdf, LabelFormat $format, LabelContent $label): void
    {
        if ($format->widthMm < $format->heightMm * self::WIDE_RATIO) {
            self::drawStacked($pdf, $format, $label);
            return;
        }
        $side = min($format->heightMm - 2 * self::MARGIN_MM, $format->widthMm * self::QR_SHARE);
        self::drawQr($pdf, QrCode::encode($label->code), self::MARGIN_MM, self::MARGIN_MM, $side);

        $x = self::MARGIN_MM + $side + self::GAP_MM;
        $width = $format->widthMm - $x - self::MARGIN_MM;

        [$titleLines, $titlePt] = self::wrap($label->title, $width, self::TITLE_MAX_PT, true);
        $lineMm = PdfDocument::pointsToMm($titlePt);
        $titleBaseline = self::MARGIN_MM + $lineMm;
        foreach ($titleLines as $i => $line) {
            $titleBaseline = self::MARGIN_MM + $lineMm + $i * $lineMm * 1.15;
            $pdf->text($x, $titleBaseline, $line, $titlePt, true);
        }

        if ($label->subtitle !== '') {
            [$subText, $subPt] = self::fit($label->subtitle, $width, self::SUBTITLE_MAX_PT, false);
            $pdf->text($x, $titleBaseline + PdfDocument::pointsToMm($subPt) * 1.4, $subText, $subPt);
        }

        [$codeText, $codePt] = self::fit($label->code, $width, self::CODE_PT, false);
        $pdf->text($x, $format->heightMm - self::CODE_BOTTOM_MM, $codeText, $codePt);
    }

    /** Near-square labels (the place holders): title across the full width, QR code below left, the rest beside it. */
    private static function drawStacked(PdfDocument $pdf, LabelFormat $format, LabelContent $label): void
    {
        $width = $format->widthMm - 2 * self::MARGIN_MM;
        [$titleLines, $titlePt] = self::wrap($label->title, $width, self::TITLE_MAX_PT, true);
        $lineMm = PdfDocument::pointsToMm($titlePt);
        $baseline = self::MARGIN_MM;
        foreach ($titleLines as $i => $line) {
            $baseline = self::MARGIN_MM + $lineMm + $i * $lineMm * 1.15;
            $pdf->text(self::MARGIN_MM, $baseline, $line, $titlePt, true);
        }

        $top = $baseline + self::GAP_MM / 2;
        $side = $format->heightMm - self::MARGIN_MM - $top;
        self::drawQr($pdf, QrCode::encode($label->code), self::MARGIN_MM, $top, $side);

        $x = self::MARGIN_MM + $side + self::GAP_MM / 2;
        $rest = $format->widthMm - $x - self::MARGIN_MM;
        if ($label->subtitle !== '') {
            [$subLines, $subPt] = self::wrap($label->subtitle, $rest, self::CODE_PT, false, self::SUBTITLE_LINES);
            foreach ($subLines as $i => $line) {
                $pdf->text($x, $top + PdfDocument::pointsToMm($subPt) * (1.4 + $i * 1.15), $line, $subPt);
            }
        }
        [$codeText, $codePt] = self::fit($label->code, $rest, self::CODE_PT, false);
        $pdf->text($x, $format->heightMm - self::CODE_BOTTOM_MM, $codeText, $codePt);
    }

    private static function drawQr(PdfDocument $pdf, QrCode $qr, float $xMm, float $yMm, float $sideMm): void
    {
        $module = $sideMm / ($qr->size() + 2 * self::QUIET_MODULES);
        $origin = self::QUIET_MODULES * $module;
        $rectangles = [];
        for ($y = 0; $y < $qr->size(); $y++) {
            $run = 0;
            for ($x = 0; $x <= $qr->size(); $x++) {
                if ($x < $qr->size() && $qr->isDark($x, $y)) {
                    $run++;
                    continue;
                }
                if ($run > 0) {
                    $rectangles[] = [$xMm + $origin + ($x - $run) * $module, $yMm + $origin + $y * $module, $run * $module, $module + self::SEAM_MM];
                }
                $run = 0;
            }
        }
        $pdf->rectangles($rectangles);
    }

    /**
     * Largest size at which the words fill at most $maxLines lines; otherwise one line, fitted or cut.
     *
     * @return array{0: list<string>, 1: float}
     */
    private static function wrap(string $text, float $widthMm, float $maxPt, bool $bold, int $maxLines = self::TITLE_LINES): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [$text];
        for ($size = $maxPt; $size >= self::MIN_PT; $size -= 0.5) {
            $lines = [];
            $current = '';
            foreach ($words as $word) {
                $candidate = $current === '' ? $word : $current . ' ' . $word;
                if ($current !== '' && PdfDocument::textWidthMm($candidate, $size, $bold) > $widthMm) {
                    $lines[] = $current;
                    $current = $word;
                    continue;
                }
                $current = $candidate;
            }
            $lines[] = $current;
            $fits = count($lines) <= $maxLines;
            foreach ($lines as $line) {
                $fits = $fits && PdfDocument::textWidthMm($line, $size, $bold) <= $widthMm;
            }
            if ($fits) {
                return [$lines, $size];
            }
        }
        [$line, $size] = self::fit($text, $widthMm, $maxPt, $bold);
        return [[$line], $size];
    }

    /**
     * Largest size up to $maxPt at which the text fits; below the minimum it is cut with an ellipsis.
     *
     * @return array{0: string, 1: float}
     */
    private static function fit(string $text, float $widthMm, float $maxPt, bool $bold): array
    {
        for ($size = $maxPt; $size >= self::MIN_PT; $size -= 0.5) {
            if (PdfDocument::textWidthMm($text, $size, $bold) <= $widthMm) {
                return [$text, $size];
            }
        }
        $cut = $text;
        while (mb_strlen($cut) > 1 && PdfDocument::textWidthMm($cut . '…', self::MIN_PT, $bold) > $widthMm) {
            $cut = mb_substr($cut, 0, -1);
        }
        return [rtrim($cut) . '…', self::MIN_PT];
    }
}
