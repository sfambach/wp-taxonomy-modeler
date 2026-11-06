<?php declare(strict_types=1);

namespace Taxmod\Core\Printing;

/**
 * The printer a label goes to: its CUPS queue, the CUPS options of that label, whether the label is turned a quarter on its
 * page, and — for a printer that starts its lines part-way along the head — that offset and the length of its line (D-1190).
 *
 * ⚠️ *The queue and the options reach `lp` on the home PC, so they are held to plain names here and again by the print
 * service; nothing that looks like a flag or a path gets through.*
 *
 * @see docs/NewConcept/90-decision-log.md D-1190
 */
final class LabelPrinter
{
    private const NAME   = '/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,126}$/';
    private const OPTION = '/^[A-Za-z0-9][A-Za-z0-9_.\-]{0,62}$/';

    /** @param array<string, string> $options CUPS option ⇒ value; '' for an option without value */
    public function __construct(
        public readonly string $queue,
        public readonly array $options = [],
        public readonly bool $turnsLabel = false,
        public readonly string $name = '',
        public readonly float $offsetMm = 0.0,
        public readonly float $lineMm = 0.0,
    ) {
        if (preg_match(self::NAME, $queue) !== 1) {
            throw new \InvalidArgumentException(sprintf('«%s» is not a CUPS queue name.', $queue));
        }
        foreach ($options as $option => $value) {
            if (preg_match(self::OPTION, (string) $option) !== 1 || ($value !== '' && preg_match(self::OPTION, $value) !== 1)) {
                throw new \InvalidArgumentException(sprintf('«%s=%s» is not a CUPS option.', $option, $value));
            }
        }
    }

    /**
     * «PageSize=w40h30 orientation-requested=4» — as a person writes them into the label, one per line or space.
     *
     * @return array<string, string>
     */
    public static function parseOptions(string $text): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $pair) {
            [$option, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $out[$option] = $value;
        }

        return $out;
    }

    /** @return array{queue: string, options: array<string, string>, turn: bool, name: string, offset: float, line: float} */
    public function toArray(): array
    {
        return ['queue' => $this->queue, 'options' => $this->options, 'turn' => $this->turnsLabel, 'name' => $this->name, 'offset' => $this->offsetMm, 'line' => $this->lineMm];
    }

    /**
     * Options may come as a map or as the text of the label's field.
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $options = $raw['options'] ?? [];
        if (is_string($options)) {
            $options = self::parseOptions($options);
        }
        $clean = [];
        foreach (is_array($options) ? $options : [] as $option => $value) {
            if (is_scalar($value)) {
                $clean[(string) $option] = (string) $value;
            }
        }
        $turn = $raw['turn'] ?? false;
        $mm   = static fn (string $key): float => is_numeric(str_replace(',', '.', (string) ($raw[$key] ?? ''))) ? max(0.0, (float) str_replace(',', '.', (string) $raw[$key])) : 0.0;

        return new self(
            trim((string) ($raw['queue'] ?? '')),
            $clean,
            $turn === true || $turn === 1 || in_array(strtolower((string) $turn), ['1', 'true', 'ja', 'yes'], true),
            trim((string) ($raw['name'] ?? '')),
            $mm('offset'),
            $mm('line'),
        );
    }
}
