<?php declare(strict_types=1);

namespace Taxmod\WordPress\Draw;


/**
 * The one read-only table of a Taxmod page: facts, a history, the lines of a form. A list with selection and actions is
 * `ListPage::table`; this is everything else, so no module draws `<table class="widefat striped">` by hand again
 * (plan-dubletten row 8, the page update after an act D-1408 finds one shape).
 *
 * @see docs/gestaltung-und-architektur.md R3
 */
final class GridMarkup
{
    /** A column whose cells are numbers: right-aligned. */
    public const NUMBER = 'number';

    /**
     * @param list<array{0: string, 1?: string}> $columns label (plain text) and optionally {@see self::NUMBER}; no columns, no head
     * @param list<list<string>>                 $rows    cells as escaped HTML (CD-8), one per column
     * @param string                             $empty   plain text in one row when there are none; '' draws an empty body
     */
    public static function table(array $columns, array $rows, string $empty = '', string $class = ''): string
    {
        $numbers = array_map(static fn (array $column): bool => ($column[1] ?? '') === self::NUMBER, $columns);
        $head    = '';

        foreach ($columns as $n => $column) {
            $head .= '<th scope="col"' . ($numbers[$n] ? ' class="is-number"' : '') . '>' . Html::escape($column[0]) . '</th>';
        }

        $body = '';

        foreach ($rows as $cells) {
            $body .= '<tr>';

            foreach (array_values($cells) as $n => $cell) {
                $body .= '<td' . (($numbers[$n] ?? false) ? ' class="is-number"' : '') . '>' . $cell . '</td>';
            }

            $body .= '</tr>';
        }

        if ($rows === [] && $empty !== '') {
            $body = '<tr class="no-items"><td colspan="' . max(1, count($columns)) . '">' . Html::escape($empty) . '</td></tr>';
        }

        return '<table class="widefat striped taxmod-grid' . ($class === '' ? '' : ' ' . Html::escape($class)) . '">'
            . ($head === '' ? '' : '<thead><tr>' . $head . '</tr></thead>') . '<tbody>' . $body . '</tbody></table>';
    }
}
