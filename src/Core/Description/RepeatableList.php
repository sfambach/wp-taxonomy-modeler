<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

use Taxmod\Core\Renderer\Control;

/**
 * The values of a field that holds several: one under the other, each with its «remove», and «add» beneath (D-527).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class RepeatableList implements Describes
{
    /** @param list<array{value: Describes, remove: ?Control}> $entries */
    public function __construct(
        public readonly array $entries,
        public readonly ?Control $add,
    ) {
    }

    public function toArray(): array
    {
        return [
            'repeatable' => array_map(static fn (array $e): array => ['value' => $e['value']->toArray(), 'remove' => $e['remove']?->toArray()], $this->entries),
            'add'        => $this->add?->toArray(),
        ];
    }
}
