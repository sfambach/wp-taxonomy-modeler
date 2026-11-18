<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * The values of a field that holds several, while editing: one link per value with moving and removing,
 * and below a choice — or a field to write — for the next one (D-859, D-858).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class ValueChain implements Describes
{
    /** @param list<array{value: string, word: string}> $entries */
    public function __construct(
        public readonly string $name,
        public readonly string $form,
        /** A list of pictures: the edge offers «as cover picture» on it (D-1205). */
        public readonly bool $pictures,
        public readonly array $entries,
        /** The next value is chosen from these; `null`: it is written. */
        public readonly ?Choosing $candidates,
        /** Media library and link dialog fill the written field (D-858); `null`: none. */
        public readonly ?MediaButtons $buttons,
        public readonly AddChosen $add,
    ) {
    }

    public function toArray(): array
    {
        return [
            'value_chain' => $this->entries,
            'name'        => $this->name,
            'form'        => $this->form,
            'pictures'    => $this->pictures,
            'candidates'  => $this->candidates?->toArray(),
            'buttons'     => $this->buttons?->toArray(),
            'add'         => $this->add->toArray(),
        ];
    }
}
