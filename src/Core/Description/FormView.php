<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * Fields one under the other, a label to the left of each (D-366); a nested form is a dividing line with its name, its fields in
 * the same label column (D-725).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class FormView implements Describes
{
    /**
     * @param list<array{name: string, field: ?Describes, hint: string, relation?: int, addable?: bool}> $lines `field` null: the dividing
     *        line of a nested form; `addable` (D-1571) true: an empty optional field, drawn hidden until «+» adds it; false: one with a
     *        value, which ✕ takes away
     * @param array<string, string> $addWords D-1571: `add`, `remove`, `choose`
     */
    public function __construct(public readonly array $lines, public readonly array $addWords = [])
    {
    }

    /** D-1571: the optional fields «+ Festlegung» offers — relation id → name; those already shown are hidden in it. @return array<int, string> */
    public function adding(): array
    {
        $out = [];

        foreach ($this->lines as $line) {
            if (isset($line['addable'], $line['relation'])) {
                $out[$line['relation']] = $line['name'];
            }
        }

        return $out;
    }

    public function toArray(): array
    {
        return ['form' => array_map(static fn (array $l): array => ['name' => $l['name'], 'field' => $l['field']?->toArray(), 'hint' => $l['hint']]
            + (isset($l['addable']) ? ['relation' => $l['relation'] ?? 0, 'addable' => $l['addable']] : []), $this->lines)];
    }
}
