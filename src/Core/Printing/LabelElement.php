<?php declare(strict_types=1);

namespace Taxmod\Core\Printing;

/**
 * One thing on a label — a text or a QR code — in a box measured in mm from the top left corner (D-1153).
 *
 * @see docs/NewConcept/90-decision-log.md D-1153
 */
final class LabelElement
{
    public const TEXT = 'text';
    public const QR   = 'qr';

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
    ) {
        if ($type !== self::TEXT && $type !== self::QR) {
            throw new \InvalidArgumentException(sprintf('Unknown label element "%s".', $type));
        }
        if ($field === '') {
            throw new \InvalidArgumentException('A label element needs a field.');
        }
        if ($widthMm <= 0.0 || $heightMm <= 0.0 || $xMm < 0.0 || $yMm < 0.0) {
            throw new \InvalidArgumentException(sprintf('The box of «%s» needs a place and a size.', $field));
        }
        if ($maxPt <= 0.0 || $lines < 1) {
            throw new \InvalidArgumentException(sprintf('«%s» needs a size and at least one line.', $field));
        }
    }

    /** @return array<string, string|float|int|bool> */
    public function toArray(): array
    {
        return array_filter([
            'type'   => $this->type,
            'field'  => $this->field,
            'x'      => $this->xMm,
            'y'      => $this->yMm,
            'w'      => $this->widthMm,
            'h'      => $this->heightMm,
            'pt'     => $this->type === self::TEXT ? $this->maxPt : null,
            'bold'   => $this->bold ?: null,
            'lines'  => $this->lines > 1 ? $this->lines : null,
            'bottom' => $this->atBottom ?: null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
