<?php declare(strict_types=1);

namespace Taxmod\Core\Tile;

/**
 * A key figure per month over a fixed window ending in the current month (D-1149): months without records count 0, so
 * the bars keep their spacing and «this month» is always the last one.
 *
 * @see docs/pakete/kacheln/konzept.md
 */
final class MonthlySeries
{
    /** @param array<string, float> $values month «YYYY-MM» => value, oldest first, every month of the window present */
    private function __construct(private readonly array $values)
    {
    }

    /**
     * @param array<string, float|int|string> $found month «YYYY-MM» => value, as the query found them; other months are dropped
     * @param string                          $current the current month «YYYY-MM»
     */
    public static function of(array $found, string $current, int $months): self
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $current, $m) !== 1) {
            throw new \InvalidArgumentException("Not a month: {$current}.");
        }

        $months = max(1, min(36, $months));
        $year   = (int) $m[1];
        $month  = (int) $m[2];
        $values = [];

        for ($back = $months - 1; $back >= 0; $back--) {
            $index = $year * 12 + ($month - 1) - $back;
            $key   = sprintf('%04d-%02d', intdiv($index, 12), $index % 12 + 1);
            $values[$key] = (float) ($found[$key] ?? 0);
        }

        return new self($values);
    }

    /** @return array<string, float> */
    public function values(): array
    {
        return $this->values;
    }

    public function current(): float
    {
        return $this->values[array_key_last($this->values)];
    }

    /** The month before the current one, or null when the window is a single month. */
    public function previous(): ?float
    {
        $values = array_values($this->values);

        return count($values) < 2 ? null : $values[count($values) - 2];
    }

    /** The mean of the complete months before the current one, or null when there are none. */
    public function averageBefore(): ?float
    {
        $before = array_slice(array_values($this->values), 0, -1);

        return $before === [] ? null : array_sum($before) / count($before);
    }

    public function total(): float
    {
        return array_sum($this->values);
    }

    /** -1, 0 or 1: the current month against the previous one. */
    public function direction(): int
    {
        $previous = $this->previous();

        return $previous === null ? 0 : $this->current() <=> $previous;
    }
}
