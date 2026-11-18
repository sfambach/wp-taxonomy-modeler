<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * Records as a table: a column per field, a row per record — or turned, a row per field — with the leading columns the surface
 * adds and the acts of each row (D-360).
 *
 * ⚠️ *When showing, a column that is empty in every row is left out; whether a cell is empty is a question about what it shows, so the
 * drawer answers it.*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class TableView implements Describes
{
    /**
     * @param list<string>                                                                                           $leadHeads
     * @param list<array{name: string, hint: string}>                                                                $columnHeads
     * @param list<array{lead: list<Describes|string>, cells: list<Describes|string>, acts: Describes|string|null}> $rows
     */
    public function __construct(
        public readonly array $leadHeads,
        public readonly array $columnHeads,
        public readonly array $rows,
        public readonly bool $withActs,
        public readonly \Taxmod\Core\Renderer\Orientation $orientation,
        public readonly bool $withHeads,
        public readonly bool $dropEmptyColumns,
    ) {
    }

    public function toArray(): array
    {
        $teil = static fn (Describes|string|null $d): mixed => $d instanceof Describes ? $d->toArray() : $d;

        // In reading order (A22): the heads above the rows.
        return [
            'table'        => ['lead_heads' => $this->leadHeads, 'column_heads' => $this->columnHeads],
            'rows'         => array_map(static fn (array $r): array => [
                'lead'  => array_map($teil, $r['lead']),
                'cells' => array_map($teil, $r['cells']),
                'acts'  => $teil($r['acts']),
            ], $this->rows),
            'with_acts'    => $this->withActs,
            'orientation'  => $this->orientation->value,
            'with_heads'   => $this->withHeads,
            'drop_empty'   => $this->dropEmptyColumns,
        ];
    }
}
