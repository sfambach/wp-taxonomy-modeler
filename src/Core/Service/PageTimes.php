<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

/**
 * The last page calls with what each took — a ring that forgets the oldest, and the summary per page.
 *
 * His word 2026-11-19: «dass du das mal mitloggst, vielleicht für jede Seite. Dann kann ich dir das schicken, dann musst du
 * nicht messen.» Measurements only: nothing of the model, nothing a page reads back to decide anything.
 *
 * An entry is kept short, because the ring is written whole with every call: `t` the second it happened, `p` the page,
 * `ms` the time, `q` the database statements, `mb` the peak memory, `o` 1 while OPcache ran,
 * `b` the ms until WordPress was loaded, `r` the ms until the page was drawn (0: no page), `h` the ms spent waiting for other servers.
 *
 * @see docs/pakete/ladezeit/konzept.md
 */
final class PageTimes
{
    /** So many calls the ring holds — few, because the whole ring is written again with every call. */
    public const KEEP = 200;

    /**
     * @param list<array<string, int|string>> $entries oldest first
     * @param array<string, int|string>       $entry   the short keys of the class comment
     * @return list<array<string, int|string>> with the entry last and never more than {@see self::KEEP}
     */
    public static function with(array $entries, array $entry): array
    {
        $entries[] = $entry;

        return array_values(array_slice($entries, -self::KEEP));
    }

    /**
     * Per page: how often it was called, the middle and the longest time, the middle count of statements.
     *
     * @param list<array<string, int|string>> $entries
     * @return array<string, array{calls: int, median_ms: int, max_ms: int, median_queries: int, last: int}> page → summary, slowest middle first
     */
    public static function summary(array $entries): array
    {
        $byPage = [];

        foreach ($entries as $entry) {
            $byPage[(string) ($entry['p'] ?? '')][] = $entry;
        }

        $out = [];

        foreach ($byPage as $page => $calls) {
            $ms      = array_map(static fn (array $c): int => (int) ($c['ms'] ?? 0), $calls);
            $queries = array_map(static fn (array $c): int => (int) ($c['q'] ?? 0), $calls);

            $out[$page] = [
                'calls'          => count($calls),
                'median_ms'      => self::middle($ms),
                'max_ms'         => max($ms),
                'median_queries' => self::middle($queries),
                'last'           => max(array_map(static fn (array $c): int => (int) ($c['t'] ?? 0), $calls)),
            ];
        }

        uasort($out, static fn (array $a, array $b): int => $b['median_ms'] <=> $a['median_ms']);

        return $out;
    }

    /** @param non-empty-list<int> $numbers */
    private static function middle(array $numbers): int
    {
        sort($numbers);
        $half = intdiv(count($numbers), 2);

        return count($numbers) % 2 === 1 ? $numbers[$half] : (int) round(($numbers[$half - 1] + $numbers[$half]) / 2);
    }
}
