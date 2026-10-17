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
        $columns = self::ordered($columns, $mainId, $position);

        $head = '';

        foreach ($columns as $column) {
            $main  = $column['id'] === $mainId;
            $word  = $main ? $mainWord : $otherWord;
            $head .= '<th class="' . self::NAME . '-value ' . self::NAME . ($main ? '-main' : '-other') . '">'
                . ($word === '' ? '' : '<span class="' . self::NAME . '-role">' . RenderResult::escape($word) . '</span>')
                . RenderResult::escape($column['label']) . '</th>';
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
                    $body    .= '<tr class="' . self::NAME . '-head"><th colspan="' . (count($columns) + 1) . '">' . RenderResult::escape($group) . '</th></tr>';
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
                $body .= '<td class="' . self::NAME . ($i === 0 && !$grouped ? '-term' : '-detail') . '">' . RenderResult::escape($label) . '</td>';
            }

            foreach ($columns as $column) {
                $main  = $column['id'] === $mainId;
                $body .= '<td class="' . self::NAME . '-value ' . self::NAME . ($main ? '-main' : '-other') . '">'
                    . RenderResult::escape($row['cells'][$column['id']] ?? '') . '</td>';
            }

            $body .= '</tr>';
        }

        if ($grouped) {
            $labelHeads = [implode(' · ', array_values(array_filter($labelHeads, static fn (string $l): bool => $l !== '')))];
        }

        $kopf = '';

        foreach ($labelHeads as $label) {
            $kopf .= '<th>' . RenderResult::escape($label) . '</th>';
        }

        return '<table class="' . self::NAME . '"><thead><tr>' . $kopf . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    /**
     * Der Hauptsatz an seinen Platz, die übrigen in ihrer Reihenfolge darum — bei «Liste» bleibt alles, wie es steht (D-924).
     *
     * @param  list<array{id: int, label: string}> $columns
     * @return list<array{id: int, label: string}>
     */
    public static function ordered(array $columns, int $mainId, ComparisonPosition $position): array
    {
        if ($position === ComparisonPosition::Listed) {
            return array_values($columns);
        }

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
