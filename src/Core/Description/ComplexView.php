<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * A composed node as a whole, like the page but for data (D-758): its fixed facts in one line, its simple fields as a form, and a
 * block per part — one record as a form, several as a table.
 *
 * ⚠️ *A block with nothing in it is left out; whether it shows anything is a question about what it draws, so the drawer answers it.*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class ComplexView implements Describes
{
    /**
     * @param array<string, Describes|string>                                                    $fixed  word ⇒ value
     * @param list<array{heading: ?string, hint: string, body: list<Describes|string>}> $blocks the first without a heading: the simple fields
     */
    public function __construct(
        public readonly array $fixed,
        public readonly array $blocks,
    ) {
    }

    public function toArray(): array
    {
        $teil = static fn (Describes|string $d): mixed => $d instanceof Describes ? $d->toArray() : $d;

        return [
            'complex' => array_map(static fn (array $b): array => ['heading' => $b['heading'], 'hint' => $b['hint'], 'body' => array_map($teil, $b['body'])], $this->blocks),
            'fixed'   => array_map($teil, $this->fixed),
        ];
    }
}
