<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\RecordInTree;

/**
 * The records of one node arranged as a tree by their «Übergeordnet» field — walked once, in memory (D-1084).
 *
 * ```mermaid
 * flowchart LR
 *   L["all records + parent ids · one query"] --> W["this · children by parent"]
 *   W --> R["rows: record + depth, in order"]
 * ```
 *
 * ⚠️ **Three rules the data cannot be trusted to keep, so the walk keeps them.** *A record whose parent is not among the records
 * (empty field, deleted parent, a record of another node) stands at the top level. A cycle (A under B under A) cannot hang the walk:
 * every record is placed at most once, and the records of a cycle that no top-level record reaches are placed at the top level
 * themselves, the lowest id first. Excluded records are left out together with everything below them.*
 *
 * @see docs/NewConcept/90-decision-log.md D-1084, D-1075
 */
final class RecordTree
{
    /**
     * @param  list<RecordInTree> $records
     * @param  list<int>          $exclude Records whose subtrees are hidden, themselves included.
     * @param  int|null           $root    Only this record and what lies below it; `null` for the whole set.
     * @return list<array{record: RecordInTree, depth: int, hasChildren: bool}>
     */
    public static function walk(array $records, ?int $root = null, array $exclude = []): array
    {
        $byId = [];

        foreach ($records as $record) {
            $byId[$record->id] ??= $record;
        }

        $aus      = array_fill_keys($exclude, true);
        $children = [];
        $top      = [];

        foreach ($byId as $id => $record) {
            $parent = $record->parentId;

            // *A parent that is missing — or the record itself — does not hold it: it stands at the top.*
            if ($parent === null || $parent === $id || ! isset($byId[$parent])) {
                $top[] = $id;
            } else {
                $children[$parent][] = $id;
            }
        }

        // *Siblings in the order a person reads them: by their word, naturally («Fach 2» before «Fach 10»), then by id.*
        $order = static function (int $a, int $b) use ($byId): int {
            return strnatcasecmp($byId[$a]->label(), $byId[$b]->label()) ?: $a <=> $b;
        };

        usort($top, $order);

        foreach ($children as &$geschwister) {
            usort($geschwister, $order);
        }

        unset($geschwister);

        $placed = [];
        $rows   = [];

        $walk = static function (int $id, int $depth) use (&$walk, &$rows, &$placed, $byId, $children, $aus): void {
            if (isset($placed[$id]) || isset($aus[$id])) {
                return;
            }

            $placed[$id] = true;
            $visible     = array_values(array_filter(
                $children[$id] ?? [],
                static fn (int $kind): bool => ! isset($aus[$kind]) && ! isset($placed[$kind])
            ));

            $rows[] = ['record' => $byId[$id], 'depth' => $depth, 'hasChildren' => $visible !== []];

            foreach ($visible as $kind) {
                $walk($kind, $depth + 1);
            }
        };

        if ($root !== null) {
            if (isset($byId[$root])) {
                $walk($root, 0);
            }

            return $rows;
        }

        foreach ($top as $id) {
            $walk($id, 0);
        }

        // ⚠️ *What no top-level record reached sits in a cycle (or under one): the walk starts it from the top, lowest id first, so
        // every record can still be chosen and the walk ends.*
        $rest = array_keys(array_diff_key($byId, $placed, $aus));
        sort($rest);

        // *The walk above placed what it reached; everything else is in or under a cycle.*

        foreach ($rest as $id) {
            if (! isset($placed[$id]) && ! self::below($id, $byId, $aus)) {
                $walk($id, 0);
            }
        }

        return $rows;
    }

    /**
     * Whether a record hangs, somewhere up its chain, under an excluded one — then it stays hidden even inside a cycle.
     *
     * @param array<int, RecordInTree> $byId
     * @param array<int, true>         $aus
     */
    private static function below(int $id, array $byId, array $aus): bool
    {
        $seen = [];

        while (isset($byId[$id]) && ! isset($seen[$id])) {
            $seen[$id] = true;
            $parent    = $byId[$id]->parentId;

            if ($parent === null) {
                return false;
            }

            if (isset($aus[$parent])) {
                return true;
            }

            $id = $parent;
        }

        return false;
    }
}
