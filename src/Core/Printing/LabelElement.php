<?php declare(strict_types=1);

namespace Taxmod\Core\Printing;

/**
 * One thing on a label — a text, a QR code or a line — in a box measured in mm from the top left corner (D-1153).
 *
 * @see docs/NewConcept/90-decision-log.md D-1153, D-1237
 */
final class LabelElement
{
    public const TEXT = 'text';
    public const QR   = 'qr';
    /** D-1237: a black bar over its box and nothing else — a cut mark between two labels on one. */
    public const LINE = 'line';

    public function __construct(
        public readonly string $type,
        public readonly string $field,
        public readonly float $xMm,
        public readonly float $yMm,
        public readonly float $widthMm,
        public readonly float $heightMm,
        /** The largest size a text may take; it shrinks until it fits its box. */
        public readonly float $maxPt = 12.0,
        public readonly bool $bold = false,
        public readonly int $lines = 1,
        /** A text sits at the top of its box, or with its last line on the bottom edge. */
        public readonly bool $atBottom = false,
        /** D-1154: the text preview and test print show where no record fills the field. */
        public readonly string $example = '',
        /** D-1217, D-1236: a component value in its short code («24k», «4n7»). */
        public readonly bool $short = false,
        /** D-1217: the text stands in the middle of its box. */
        public readonly bool $centred = false,
        /** D-1237: the text ends on the right edge of its box. */
        public readonly bool $flushRight = false,
    ) {
        if (! in_array($type, [self::TEXT, self::QR, self::LINE], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown label element "%s".', $type));
        }
        if ($field === '' && $type !== self::LINE) {
            throw new \InvalidArgumentException('A label element needs a field.');
        }
        if ($widthMm <= 0.0 || $heightMm <= 0.0 || $xMm < 0.0 || $yMm < 0.0) {
            throw new \InvalidArgumentException(sprintf('The box of «%s» needs a place and a size.', $field));
        }
        if ($maxPt <= 0.0 || $lines < 1) {
            throw new \InvalidArgumentException(sprintf('«%s» needs a size and at least one line.', $field));
        }
    }

    /**
     * D-1217: «Widerstandswert|Kapazität» — the first of these fields the record has a value for.
     *
     * @return list<string>
     */
    public function alternatives(): array
    {
        if ($this->type === self::LINE) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode('|', $this->field)), static fn (string $f): bool => $f !== ''));
    }

    /** @return array<string, string|float|int|bool> */
    public function toArray(): array
    {
        return array_filter([
            'type'   => $this->type,
            'field'  => $this->field === '' ? null : $this->field,
            'x'      => $this->xMm,
            'y'      => $this->yMm,
            'w'      => $this->widthMm,
            'h'      => $this->heightMm,
            'pt'     => $this->type === self::TEXT ? $this->maxPt : null,
            'bold'   => $this->bold ?: null,
            'lines'  => $this->lines > 1 ? $this->lines : null,
            'bottom' => $this->atBottom ?: null,
            'example' => $this->example === '' ? null : $this->example,
            'short'  => $this->short ?: null,
            'center' => $this->centred ?: null,
            'right'  => $this->flushRight ?: null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
