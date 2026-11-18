<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * A waiting change of an edge's kind (D-699): the tick «I confirm» and the sentence saying what the change costs.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class KindConfirm implements Describes
{
    public function __construct(
        public readonly string $name,
        public readonly string $form,
        public readonly string $confirmWord,
        public readonly string $pending,
    ) {
    }

    public function toArray(): array
    {
        return ['kind_confirm' => $this->name, 'form' => $this->form, 'confirm_word' => $this->confirmWord, 'pending' => $this->pending];
    }
}
