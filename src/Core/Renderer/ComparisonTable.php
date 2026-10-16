<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * Mehrere Sätze nebeneinander, einer davon der Hauptsatz des Beitrags — die Anzeige «Vergleich» des Satz-Blocks
 * ([D-916](../../../docs/NewConcept/90-decision-log.md), [D-920](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ```mermaid
 * flowchart LR
 *   S["Spalten: Sätze"] --> O["ordered: Hauptsatz vorn oder in der Mitte"]
 *   Z["Zeilen: Beschriftung + Zelle je Satz"] --> T["Tabelle"]
 *   O --> T
 * ```
 *
 * ⚠️ **Die Wörter über den Spalten kommen vom Rand** (`AR-2`): *der Kern kennt die Rolle «Hauptsatz» und «Vergleich»,
 * nicht ihre Übersetzung; ein leeres Wort heisst «keins» — dann trägt die Farbe allein die Rolle.* Prozent-Abweichungen gehören
 * nicht hierher (D-920, INF-067).
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class ComparisonTable
{
    public const NAME = 'taxmod-comparison';

    /**
     * @param list<array{id: int, label: string}>                           $columns   Die Sätze in ihrer gewählten Reihenfolge.
     * @param list<string>                                                  $labelHeads Überschriften der Beschriftungsspalten.
     * @param list<array{labels: list<string>, cells: array<int, string>}>  $rows      Zellen je Satz-Id; fehlt eine, bleibt sie leer.
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
        $columns = self::ordered($columns, $mainId, $position);

        $head = '';

        foreach ($labelHeads as $label) {
            $head .= '<th>' . RenderResult::escape($label) . '</th>';
        }

        foreach ($columns as $column) {
            $main  = $column['id'] === $mainId;
            $word  = $main ? $mainWord : $otherWord;
            $head .= '<th class="' . self::NAME . '-value ' . self::NAME . ($main ? '-main' : '-other') . '">'
                . ($word === '' ? '' : '<span class="' . self::NAME . '-role">' . RenderResult::escape($word) . '</span>')
                . RenderResult::escape($column['label']) . '</th>';
        }

        $body     = '';
        $previous = null;

        foreach ($rows as $row) {
            $labels = $row['labels'];
            $first  = $labels[0] ?? '';
            $starts = $first !== $previous;
            $previous = $first;

            // *Eine Gruppe nennt ihren ersten Begriff einmal — wie seine Tabellen es taten (Doom, Doom, Doom …).*
            if (!$starts && $labels !== []) {
                $labels[0] = '';
            }

            $body .= '<tr' . ($starts ? ' class="' . self::NAME . '-group"' : '') . '>';

            foreach ($labels as $i => $label) {
                $body .= '<td class="' . self::NAME . ($i === 0 ? '-term' : '-detail') . '">' . RenderResult::escape($label) . '</td>';
            }

            foreach ($columns as $column) {
                $main  = $column['id'] === $mainId;
                $body .= '<td class="' . self::NAME . '-value ' . self::NAME . ($main ? '-main' : '-other') . '">'
                    . RenderResult::escape($row['cells'][$column['id']] ?? '') . '</td>';
            }

            $body .= '</tr>';
        }

        return '<table class="' . self::NAME . '"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    /**
     * Der Hauptsatz an seinen Platz, die übrigen in ihrer Reihenfolge darum.
     *
     * @param  list<array{id: int, label: string}> $columns
     * @return list<array{id: int, label: string}>
     */
    public static function ordered(array $columns, int $mainId, ComparisonPosition $position): array
    {
        $main = null;
        $rest = [];

        foreach ($columns as $column) {
            if ($column['id'] === $mainId && $main === null) {
                $main = $column;
            } else {
                $rest[] = $column;
            }
        }

        if ($main === null) {
            return $rest;
        }

        $at = $position === ComparisonPosition::Middle ? intdiv(count($rest) + 1, 2) : 0;
        array_splice($rest, $at, 0, [$main]);

        return $rest;
    }
}
