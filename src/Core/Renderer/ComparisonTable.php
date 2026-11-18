<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * Mehrere Sätze nebeneinander, einer davon der Hauptsatz des Beitrags — die Reihenfolge der Spalten der Anzeige «Vergleich» des Satz-Blocks
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
 * ⚠️ *Gezeichnet wird die Tabelle am Rand ({@see \Taxmod\WordPress\Draw\ComparisonTableMarkup}) seit Renderer-Schritt 6c (D-1429 O3, D-638).*
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class ComparisonTable
{
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
