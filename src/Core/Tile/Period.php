<?php declare(strict_types=1);

namespace Taxmod\Core\Tile;

/**
 * One day, month or year of the period view (D-1167): where it starts and ends, the one before and after, and the steps a
 * chart of it shows.
 *
 * The steps of a day are the fourteen days ending on it, not its hours: a Vorgang carries a date without a time, so hours
 * would put everything at midnight.
 *
 * ```mermaid
 * flowchart LR
 *   A["span + any date in it"] --> P["Period: start … end"]
 *   P --> B["previous · next"]
 *   P --> S["steps: day → 14 days, month → its days, year → its months"]
 * ```
 *
 * @see docs/pakete/kacheln/konzept.md §6
 */
final class Period
{
    /** How many days the chart of a single day shows, the day itself last. */
    public const DAY_STEPS = 14;

    private function __construct(public readonly PeriodSpan $span, private readonly \DateTimeImmutable $start)
    {
    }

    /** The period of this span that holds `$date` («YYYY-MM-DD»); a wrong date is refused. */
    public static function of(PeriodSpan $span, string $date): self
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));

        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException(sprintf('«%s» is not a date YYYY-MM-DD.', $date));
        }

        return new self($span, match ($span) {
            PeriodSpan::Day   => $day,
            PeriodSpan::Month => $day->modify('first day of this month'),
            PeriodSpan::Year  => $day->setDate((int) $day->format('Y'), 1, 1),
        });
    }

    /** The first day, «YYYY-MM-DD». */
    public function start(): string
    {
        return $this->start->format('Y-m-d');
    }

    /** The first day after it, «YYYY-MM-DD» — the end is exclusive. */
    public function end(): string
    {
        return $this->step(1)->format('Y-m-d');
    }

    public function previous(): self
    {
        return new self($this->span, $this->step(-1));
    }

    public function next(): self
    {
        return new self($this->span, $this->step(1));
    }

    public function contains(string $date): bool
    {
        $day = substr($date, 0, 10);

        return $day >= $this->start() && $day < $this->end();
    }

    /**
     * The steps of its chart, oldest first, each the key a date falls in: «YYYY-MM-DD», for a year «YYYY-MM».
     *
     * @return list<string>
     */
    public function steps(): array
    {
        $out = [];

        if ($this->span === PeriodSpan::Year) {
            for ($m = 0; $m < 12; $m++) {
                $out[] = $this->start->modify("+{$m} months")->format('Y-m');
            }

            return $out;
        }

        $first = $this->span === PeriodSpan::Day ? $this->start->modify('-' . (self::DAY_STEPS - 1) . ' days') : $this->start;
        $days  = $this->span === PeriodSpan::Day ? self::DAY_STEPS : (int) $this->start->format('t');

        for ($d = 0; $d < $days; $d++) {
            $out[] = $first->modify("+{$d} days")->format('Y-m-d');
        }

        return $out;
    }

    /** The step a date «YYYY-MM-DD…» falls in. */
    public function stepOf(string $date): string
    {
        return $this->span === PeriodSpan::Year ? substr($date, 0, 7) : substr($date, 0, 10);
    }

    /** The first day the chart shows — earlier than {@see start()} only for a day. */
    public function chartStart(): string
    {
        return $this->steps()[0] . ($this->span === PeriodSpan::Year ? '-01' : '');
    }

    private function step(int $by): \DateTimeImmutable
    {
        return match ($this->span) {
            PeriodSpan::Day   => $this->start->modify(sprintf('%+d days', $by)),
            PeriodSpan::Month => $this->start->modify(sprintf('%+d months', $by)),
            PeriodSpan::Year  => $this->start->modify(sprintf('%+d years', $by)),
        };
    }
}
