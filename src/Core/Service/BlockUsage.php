<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

/**
 * Lists every use of the record block in posts and pages, one row per block, so a change to a renderer can be checked against
 * the posts it touches before and after (D-1051, design section 18).
 *
 * @see docs/NewConcept/90-decision-log.md D-1052
 */
final class BlockUsage
{
    public const BLOCK = 'taxmod/record';

    public const COLUMNS = ['post', 'title', 'status', 'type', 'display', 'node', 'record', 'records', 'field', 'via', 'fields'];

    /**
     * @param list<array{id: int, title: string, status: string, type: string, blocks: list<array<string, mixed>>}> $posts blocks as WordPress parses them
     * @return list<array<string, int|string>> one row per record block, keyed by COLUMNS; node stays 0 when the block holds none
     */
    public function rows(array $posts): array
    {
        $rows = [];

        foreach ($posts as $post) {
            foreach ($this->recordBlocks($post['blocks']) as $attributes) {
                $rows[] = [
                    'post'    => $post['id'],
                    'title'   => $post['title'],
                    'status'  => $post['status'],
                    'type'    => $post['type'],
                    'display' => (string) ($attributes['display'] ?? 'record'),
                    'node'    => (int) ($attributes['node'] ?? 0),
                    'record'  => (int) ($attributes['record'] ?? 0),
                    'records' => (string) ($attributes['records'] ?? ''),
                    'field'   => (int) ($attributes['field'] ?? 0),
                    'via'     => (int) ($attributes['via'] ?? 0),
                    'fields'  => (string) ($attributes['rowFields'] ?? ''),
                ];
            }
        }

        return $rows;
    }

    /**
     * Blocks older than 0.1.10 hold no node; the caller looks it up from their record, once for all of them.
     *
     * @param list<array<string, int|string>> $rows
     * @return list<int>
     */
    public function recordsWithoutNode(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            if ($row['node'] === 0 && $row['record'] > 0) {
                $ids[(int) $row['record']] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param list<array<string, int|string>> $rows
     * @param array<int, int> $nodeOfRecord
     * @return list<array<string, int|string>>
     */
    public function withNodes(array $rows, array $nodeOfRecord): array
    {
        return array_map(static fn (array $row): array => $row['node'] === 0 && isset($nodeOfRecord[(int) $row['record']])
            ? ['node' => $nodeOfRecord[(int) $row['record']]] + $row
            : $row, $rows);
    }

    /** @param list<array<string, int|string>> $rows */
    public function csv(array $rows): string
    {
        $lines = [$this->csvLine(self::COLUMNS)];

        foreach ($rows as $row) {
            $lines[] = $this->csvLine(array_map(static fn (string $c): string => (string) $row[$c], self::COLUMNS));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return list<array<string, mixed>>
     */
    private function recordBlocks(array $blocks): array
    {
        $found = [];
        $open  = $blocks;

        // A stack instead of recursion: groups and columns nest blocks to any depth.
        while ($open !== []) {
            $block = array_shift($open);

            if (($block['blockName'] ?? null) === self::BLOCK) {
                $found[] = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            }

            if (is_array($block['innerBlocks'] ?? null) && $block['innerBlocks'] !== []) {
                array_unshift($open, ...array_values($block['innerBlocks']));
            }
        }

        return $found;
    }

    /** @param list<string> $cells */
    private function csvLine(array $cells): string
    {
        return implode(',', array_map(static fn (string $c): string => preg_match('/[",\n]/', $c) === 1 ? '"' . str_replace('"', '""', $c) . '"' : $c, $cells));
    }
}
