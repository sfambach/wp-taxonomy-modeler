<?php declare(strict_types=1);

namespace Taxmod\Core\Printing;

/**
 * One label as a module hands it over: the size, the layout of its template, the values of its record (D-1153) and the
 * printer its template names (D-1190).
 *
 * ⚠️ *It travels as JSON — in the dialog's form and in a print job — so the print service draws exactly what the person saw,
 * even if the template changes before the job is fetched.*
 *
 * @see docs/NewConcept/90-decision-log.md D-1153
 */
final class FilledLabel
{
    /** @param array<string, string> $values field ⇒ text */
    public function __construct(
        public readonly LabelFormat $format,
        public readonly LabelLayout $layout,
        public readonly array $values,
        public readonly string $templateName = '',
        public readonly ?LabelPrinter $printer = null,
    ) {
        if (! $layout->fitsOn($format)) {
            throw new \InvalidArgumentException(sprintf('An element of the layout reaches past the %s × %s mm label.', $format->widthMm, $format->heightMm));
        }
    }

    public function code(): string
    {
        return (string) ($this->values['code'] ?? '');
    }

    public function title(): string
    {
        return (string) ($this->values['title'] ?? '');
    }

    /** @param int<1, max> $copies */
    public function render(int $copies = 1): string
    {
        return LabelDocument::renderLayout($this->format, $this->layout, array_fill(0, max(1, $copies), $this->values));
    }

    public function toJson(): string
    {
        $format = ['key' => $this->format->key, 'w' => $this->format->widthMm, 'h' => $this->format->heightMm, 'roll' => $this->format->rollMm];
        if ($this->format->turned !== null) {
            $format['turn'] = $this->format->turned;
        }

        return json_encode([
            'format'   => $format,
            'layout'   => json_decode($this->layout->toJson(), true, 8, JSON_THROW_ON_ERROR),
            'values'   => $this->values,
            'template' => $this->templateName,
        ] + ($this->printer === null ? [] : ['printer' => $this->printer->toArray()]), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): self
    {
        try {
            $raw = json_decode($json, true, 10, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('The label is not valid JSON: ' . $e->getMessage(), 0, $e);
        }
        $format = is_array($raw['format'] ?? null) ? $raw['format'] : [];
        $key    = preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) ($format['key'] ?? '')));
        $size   = static fn (string $k): float => is_numeric($format[$k] ?? null) ? (float) $format[$k] : 0.0;
        if ($key === '' || $size('w') <= 0.0 || $size('h') <= 0.0) {
            throw new \InvalidArgumentException('The label needs a format with a key and a size.');
        }
        $values = [];
        foreach (is_array($raw['values'] ?? null) ? $raw['values'] : [] as $field => $value) {
            if (is_scalar($value)) {
                $values[(string) $field] = (string) $value;
            }
        }

        $printer = is_array($raw['printer'] ?? null) && trim((string) ($raw['printer']['queue'] ?? '')) !== '' ? LabelPrinter::fromArray($raw['printer']) : null;
        // D-1190: whether the label is turned is the printer's word; without one the roll decides as before.
        $turned = is_bool($format['turn'] ?? null) ? $format['turn'] : $printer?->turnsLabel;

        return new self(
            new LabelFormat($key, $size('w'), $size('h'), $size('roll') > 0.0 ? $size('roll') : $size('w'), $turned, $printer?->offsetMm ?? 0.0, $printer?->lineMm ?? 0.0),
            LabelLayout::fromJson(json_encode($raw['layout'] ?? null, JSON_THROW_ON_ERROR)),
            $values,
            (string) ($raw['template'] ?? ''),
            $printer,
        );
    }
}
