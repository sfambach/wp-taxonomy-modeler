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
    /** @param list<array{name: string, field: ?Describes, hint: string}> $lines `field` null: the dividing line of a nested form */
    public function __construct(public readonly array $lines)
    {
    }

    public function toArray(): array
    {
        return ['form' => array_map(static fn (array $l): array => ['name' => $l['name'], 'field' => $l['field']?->toArray(), 'hint' => $l['hint']], $this->lines)];
    }
}
