<?php declare(strict_types=1);

namespace Taxmod\Core\Printing;

/**
 * A minimal PDF 1.4 writer: pages of any size in millimetres, filled rectangles and Helvetica text.
 *
 * Enough for labels and simple printouts (D-1032); no font is embedded, the two standard fonts
 * Helvetica and Helvetica-Bold are used with WinAnsi encoding, so umlauts print.
 *
 * @see docs/NewConcept/90-decision-log.md D-1032
 */
final class PdfDocument
{
    private const POINTS_PER_MM = 72 / 25.4;

    /** @var list<array{width: float, height: float, content: string}> */
    private array $pages = [];

    public function addPage(float $widthMm, float $heightMm): void
    {
        $this->pages[] = ['width' => $widthMm * self::POINTS_PER_MM, 'height' => $heightMm * self::POINTS_PER_MM, 'content' => ''];
    }

    /**
     * Filled black rectangles as one path, so touching rectangles show no hairline seams; origin top left, millimetres.
     *
     * @param list<array{0: float, 1: float, 2: float, 3: float}> $rectangles x, y, width, height
     */
    public function rectangles(array $rectangles): void
    {
        $page = $this->currentHeight();
        $path = '';
        foreach ($rectangles as [$xMm, $yMm, $widthMm, $heightMm]) {
            $path .= sprintf(
                "%s %s %s %s re\n",
                self::number($xMm * self::POINTS_PER_MM),
                self::number($page - ($yMm + $heightMm) * self::POINTS_PER_MM),
                self::number($widthMm * self::POINTS_PER_MM),
                self::number($heightMm * self::POINTS_PER_MM),
            );
        }
        if ($path !== '') {
            $this->append($path . "f\n");
        }
    }

    /** Text with its baseline at $baselineMm from the top; UTF-8 in. */
    public function text(float $xMm, float $baselineMm, string $text, float $sizePt, bool $bold = false): void
    {
        $page = $this->currentHeight();
        $this->append(sprintf(
            "BT /%s %s Tf %s %s Td (%s) Tj ET\n",
            $bold ? 'F2' : 'F1',
            self::number($sizePt),
            self::number($xMm * self::POINTS_PER_MM),
            self::number($page - $baselineMm * self::POINTS_PER_MM),
            self::escape(self::winAnsi($text)),
        ));
    }

    /** Width of UTF-8 text in millimetres. */
    public static function textWidthMm(string $text, float $sizePt, bool $bold = false): float
    {
        return HelveticaMetrics::width(self::winAnsi($text), $sizePt, $bold) / self::POINTS_PER_MM;
    }

    public static function pointsToMm(float $points): float
    {
        return $points / self::POINTS_PER_MM;
    }

    public function render(): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $kids = [];
        $next = 5;
        foreach ($this->pages as $page) {
            $pageId = $next++;
            $contentId = $next++;
            $kids[] = $pageId . ' 0 R';
            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::number($page['width']),
                self::number($page['height']),
                $contentId,
            );
            $objects[$contentId] = sprintf("<< /Length %d >>\nstream\n%sendstream", strlen($page['content']), $page['content']);
        }
        $objects[2] = sprintf('<< /Type /Pages /Kids [%s] /Count %d >>', implode(' ', $kids), count($kids));
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n0000000000 65535 f \n", count($objects) + 1);
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        return $pdf . sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n", count($objects) + 1, $xref);
    }

    private function currentHeight(): float
    {
        if ($this->pages === []) {
            throw new \LogicException('Add a page before drawing on it.');
        }
        return $this->pages[count($this->pages) - 1]['height'];
    }

    private function append(string $operators): void
    {
        $this->pages[count($this->pages) - 1]['content'] .= $operators;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');
    }

    private static function winAnsi(string $utf8): string
    {
        return mb_convert_encoding($utf8, 'Windows-1252', 'UTF-8');
    }

    private static function escape(string $text): string
    {
        return strtr($text, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => ' ', "\n" => ' ']);
    }
}
