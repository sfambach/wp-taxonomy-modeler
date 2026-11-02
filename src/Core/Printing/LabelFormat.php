<?php declare(strict_types=1);

namespace Taxmod\Core\Printing;

/**
 * The size of one label on a roll, with the label printed across the roll (D-1032).
 *
 * @see docs/NewConcept/90-decision-log.md D-1032
 */
final class LabelFormat
{
    public function __construct(
        public readonly string $key,
        public readonly float $widthMm,
        public readonly float $heightMm,
        public readonly float $rollMm,
    ) {
    }

    /**
     * The formats shipped today: 62 mm roll (QL-810) with a 100 mm label, and the 102 mm roll (QL-1100).
     *
     * @return array<string, self>
     */
    public static function shipped(): array
    {
        $formats = [
            new self('roll62', 100.0, 62.0, 62.0),
            new self('roll102', 102.0, 62.0, 102.0),
        ];
        return array_combine(array_map(static fn (self $format): string => $format->key, $formats), $formats);
    }

    public static function byKey(string $key): self
    {
        $formats = self::shipped();
        if (!isset($formats[$key])) {
            throw new \InvalidArgumentException(sprintf('Unknown label format "%s".', $key));
        }
        return $formats[$key];
    }
}
