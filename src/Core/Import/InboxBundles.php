<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * Puts the groups of the inbox that belong to one purchase into one box (D-970): a cart split per seller sends one mail
 * per sub-order, and he reads them together, in the order they came.
 *
 * ```mermaid
 * flowchart LR
 *   G["group"] --> B{"source names a bundle?"}
 *   B -->|yes| K1["platform + bundle"]
 *   B -->|no| E{"order already in the model?"}
 *   E -->|yes| K2["platform + its order date"]
 *   E -->|no| K3["alone"]
 * ```
 *
 * @see docs/pakete/import/konzept.md §3.5
 */
final class InboxBundles
{
    /**
     * @param array<int, array{platform: string, bundle: ?string, orderDate: ?string, first: string}> $groups group index =>
     *        its platform, the bundle a source named (evidence `bundle`), the order date of the order it belongs to in
     *        the model, and when its first message came (Y-m-d H:i:s)
     * @return list<list<int>> group indexes per box; boxes and their groups in the order their first message came
     */
    public static function of(array $groups): array
    {
        $boxes = [];
        $first = [];

        foreach ($groups as $index => $group) {
            $platform = mb_strtolower(trim($group['platform']));
            $key = match (true) {
                ($group['bundle'] ?? '') !== '' && $group['bundle'] !== null => $platform . '|b|' . $group['bundle'],
                ($group['orderDate'] ?? '') !== '' && $group['orderDate'] !== null => $platform . '|d|' . substr($group['orderDate'], 0, 10),
                default => 'alone|' . $index,
            };
            $boxes[$key][] = $index;
            $first[$key] = min($first[$key] ?? $group['first'], $group['first']);
        }

        foreach ($boxes as $key => $indexes) {
            usort($indexes, static fn (int $a, int $b): int => [$groups[$a]['first'], $a] <=> [$groups[$b]['first'], $b]);
            $boxes[$key] = $indexes;
        }

        uksort($boxes, static fn (string $a, string $b): int => [$first[$a], $boxes[$a][0]] <=> [$first[$b], $boxes[$b][0]]);

        return array_values($boxes);
    }
}
