<?php declare(strict_types=1);

namespace Taxmod\Core\Listing;

/**
 * What a list shows, the same for every list (D-1054, design 7.2): the columns the user chose — or the starting set —, the rows
 * the search and the column filters keep, in the chosen order. Search and filters always mean «contains», ignoring case; the
 * search looks at every column, shown or not.
 *
 * ```mermaid
 * flowchart LR
 *   R["rows: cells per column key"] --> S["search: any cell contains"] --> F["column filters: that cell contains"] --> O["order by a column"]
 *   C["columns + user's choice"] --> V["visible columns"]
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md D-1054
 */
final class ListView
{
    /**
     * @param list<ListColumn>        $columns in the order they stand
     * @param list<string>|null       $chosen  the user's choice of non-fixed columns; null for the starting set
     * @param array<string, string>   $filters column key => text the cell must contain
     */
    public function __construct(
        private readonly array $columns,
        private readonly ?array $chosen = null,
        public readonly string $search = '',
        private readonly array $filters = [],
        public readonly string $orderBy = '',
        public readonly bool $descending = false,
    ) {
    }

    /** @return list<ListColumn> */
    public function columns(): array
    {
        return $this->columns;
    }

    /** @return list<ListColumn> fixed columns and the chosen ones, in definition order; a choice naming no known column falls back to the starting set */
    public function visible(): array
    {
        $known  = array_map(static fn (ListColumn $c): string => $c->key, $this->columns);
        $chosen = $this->chosen === null ? [] : array_values(array_intersect($this->chosen, $known));
        $start  = $this->chosen === null || $chosen === [] && $this->chosen !== [];

        return array_values(array_filter($this->columns, static fn (ListColumn $c): bool => $c->fixed || ($start ? $c->default : in_array($c->key, $chosen, true))));
    }

    /** @return array<string, string> the column filters that are set, for known columns only */
    public function filters(): array
    {
        $known = array_flip(array_map(static fn (ListColumn $c): string => $c->key, $this->columns));

        return array_filter(array_intersect_key($this->filters, $known), static fn (string $text): bool => trim($text) !== '');
    }

    /**
     * @template T of array{cells: array<string, string>, sort?: array<string, int|float|string>, words?: list<string>}
     * @param list<T> $rows cells as plain text per column key; `sort` overrides a cell's text for ordering, `words` adds search text
     * @return list<T>
     */
    public function apply(array $rows): array
    {
        $search  = trim($this->search);
        $filters = $this->filters();
        $kept    = array_values(array_filter($rows, static function (array $row) use ($search, $filters): bool {
            if ($search !== '' && ! self::anyContains([...array_values($row['cells']), ...($row['words'] ?? [])], $search)) {
                return false;
            }

            foreach ($filters as $key => $text) {
                if (! self::anyContains([$row['cells'][$key] ?? ''], trim($text))) {
                    return false;
                }
            }

            return true;
        }));

        $key = $this->orderBy;

        if ($key === '' || ! in_array($key, array_map(static fn (ListColumn $c): string => $c->key, $this->columns), true)) {
            return $kept;
        }

        $value = static fn (array $row): int|float|string => $row['sort'][$key] ?? mb_strtolower($row['cells'][$key] ?? '');
        usort($kept, fn (array $a, array $b): int => ($this->descending ? -1 : 1) * (is_string($value($a)) && is_string($value($b))
            ? strnatcmp($value($a), $value($b))
            : $value($a) <=> $value($b)));

        return $kept;
    }

    /**
     * One page of the kept rows, so a list of thousands draws only what is seen (design 20).
     *
     * @template T
     * @param list<T> $rows
     * @return list<T>
     */
    public static function page(array $rows, int $page, int $perPage): array
    {
        return array_slice($rows, (self::clampPage($page, count($rows), $perPage) - 1) * max(1, $perPage), max(1, $perPage));
    }

    /** The page number made to fit: at least 1, at most the last page. */
    public static function clampPage(int $page, int $total, int $perPage): int
    {
        return max(1, min($page, (int) ceil(max(1, $total) / max(1, $perPage))));
    }

    /** @param list<string> $texts */
    private static function anyContains(array $texts, string $needle): bool
    {
        foreach ($texts as $text) {
            if (mb_stripos($text, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
