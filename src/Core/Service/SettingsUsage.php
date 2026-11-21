<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

/**
 * Where the settings stand: per attribute, how many rows a node, a field (a use site) or a settings object carries.
 * Read only — the measure for «settings only at the data element» (Stefan 2026-11-21, /mnt/project-files/taxmod-felder/konzept.md §6).
 *
 * ```mermaid
 * flowchart LR
 *   R["settings_value rows<br>grouped: attribute · carrier · active"] --> U["SettingsUsage::summary"]
 *   U --> S["per attribute: node · field · object · inactive"]
 * ```
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class SettingsUsage
{
    public const NODE   = 'node';
    public const FIELD  = 'field';
    public const OBJECT = 'object';

    /**
     * @param  list<array{attribute: string, carrier: string, active: bool, count: int}> $groups
     * @return list<array{attribute: string, node: int, field: int, object: int, inactive: int, total: int}> most fields first, then by name
     */
    public function summary(array $groups): array
    {
        $by = [];

        foreach ($groups as $group) {
            $row = $by[$group['attribute']] ?? ['attribute' => $group['attribute'], self::NODE => 0, self::FIELD => 0, self::OBJECT => 0, 'inactive' => 0, 'total' => 0];

            if (! $group['active']) {
                $row['inactive'] += $group['count'];
            } elseif (in_array($group['carrier'], [self::NODE, self::FIELD, self::OBJECT], true)) {
                $row[$group['carrier']] += $group['count'];
            }

            $row['total']             += $group['count'];
            $by[$group['attribute']] = $row;
        }

        $rows = array_values($by);
        usort($rows, static fn (array $a, array $b): int => [$b[self::FIELD], $a['attribute']] <=> [$a[self::FIELD], $b['attribute']]);

        return $rows;
    }
}
