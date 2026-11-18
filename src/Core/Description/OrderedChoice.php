<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * Candidates chosen in an order that matters — each removable, the written ones movable up and down — and the free ones offered to add
 * (D-794).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class OrderedChoice implements Describes
{
    /** @param list<array{id: int, word: string, name: string, positionName: ?string, position: int}> $chosen `positionName` null: not written yet, not movable */
    public function __construct(
        public readonly array $chosen,
        public readonly string $form,
        public readonly Choosing $offer,
        public readonly AddChosen $add,
    ) {
    }

    public function toArray(): array
    {
        return ['ordered_choice' => $this->chosen, 'form' => $this->form, 'offer' => $this->offer->toArray(), 'add' => $this->add->toArray()];
    }
}
