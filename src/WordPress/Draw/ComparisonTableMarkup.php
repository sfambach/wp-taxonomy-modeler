<?php declare(strict_types=1);

namespace Taxmod\WordPress\Draw;

use Taxmod\Core\Renderer\ComparisonPosition;
use Taxmod\Core\Renderer\ComparisonTable;

/**
 * The comparison table of the record block: several records side by side, one of them the post's main record (D-916, D-920, D-922).
 *
 * ⚠️ *The words over the columns come from the caller (`AR-2`); an empty word means «none» — then the colour alone carries the role.
 * Drawn at the edge since renderer step 6c (D-1429 O3); the order of the columns stays in the core ({@see ComparisonTable::ordered()}).*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class ComparisonTableMarkup
{
    public const NAME = 'taxmod-comparison';

    /**
     * @param list<array{id: int, label: string}>                           $columns   Die Sätze in ihrer gewählten Reihenfolge.
     * @param list<string>                                                  $labelHeads Überschriften der Beschriftungsspalten.
     * @param list<array{group?: string, labels: list<string>, cells: array<int, string>}> $rows Zellen je Satz-Id; fehlt eine, bleibt sie leer.
     *        Trägt eine Zeile `group`, bekommt jede Gruppe eine Kopfzeile über die ganze Breite und die Beschriftungen stehen in einer
     *        Spalte, mit « · » verbunden ([D-922](../../../docs/NewConcept/90-decision-log.md)).
     */
    public static function render(
        array $columns,
        int $mainId,
        ComparisonPosition $position,
        array $labelHeads,
        array $rows,
        string $mainWord,
        string $otherWord,
    ): string {
        $columns = ComparisonTable::ordered($columns, $mainId, $position);

        $head = '';

        foreach ($columns as $column) {
            $main  = $column['id'] === $mainId;
            $word  = $main ? $mainWord : $otherWord;
            $head .= '<th class="' . self::NAME . '-value ' . self::NAME . ($main ? '-main' : '-other') . '">'
                . ($word === '' ? '' : '<span class="' . self::NAME . '-role">' . self::e($word) . '</span>')
                . self::e($column['label']) . '</th>';
        }

        $grouped = array_filter($rows, static fn (array $row): bool => ($row['group'] ?? '') !== '') !== [];
        $body     = '';
        $previous = null;

        foreach ($rows as $row) {
            $labels = $row['labels'];

            if ($grouped) {
                $group = (string) ($row['group'] ?? '');

                if ($group !== $previous) {
                    $previous = $group;
                    $body    .= '<tr class="' . self::NAME . '-head"><th colspan="' . (count($columns) + 1) . '">' . self::e($group) . '</th></tr>';
                }

                $labels = [implode(' · ', array_values(array_filter($labels, static fn (string $l): bool => $l !== '')))];
                $starts = false;
            } else {
                $first    = $labels[0] ?? '';
                $starts   = $first !== $previous;
                $previous = $first;

                // *Eine Gruppe nennt ihren ersten Begriff einmal — wie seine Tabellen es taten (Doom, Doom, Doom …).*
                if (!$starts && $labels !== []) {
                    $labels[0] = '';
                }
            }

            $body .= '<tr' . ($starts ? ' class="' . self::NAME . '-group"' : '') . '>';

            foreach ($labels as $i => $label) {
                $body .= '<td class="' . self::NAME . ($i === 0 && !$grouped ? '-term' : '-detail') . '">' . self::e($label) . '</td>';
            }

            foreach ($columns as $column) {
                $main  = $column['id'] === $mainId;
                $body .= '<td class="' . self::NAME . '-value ' . self::NAME . ($main ? '-main' : '-other') . '">'
                    . self::e($row['cells'][$column['id']] ?? '') . '</td>';
            }

            $body .= '</tr>';
        }

        if ($grouped) {
            $labelHeads = [implode(' · ', array_values(array_filter($labelHeads, static fn (string $l): bool => $l !== '')))];
        }

        $kopf = '';

        foreach ($labelHeads as $label) {
            $kopf .= '<th>' . self::e($label) . '</th>';
        }

        return '<table class="' . self::NAME . '"><thead><tr>' . $kopf . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    private static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
