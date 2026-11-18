<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * A media field to fill: the address, the two buttons, and the link to what it holds now.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class MediaPicker implements Describes
{
    public function __construct(
        public readonly string $name,
        public readonly string $address,
        public readonly string $form,
        /** `null`: the bare address field, where nothing can be picked. */
        public readonly ?MediaButtons $buttons,
        public readonly ?MediaLink $current,
    ) {
    }

    public function toArray(): array
    {
        return ['media_picker' => $this->name, 'address' => $this->address, 'form' => $this->form, 'buttons' => $this->buttons?->toArray(), 'current' => $this->current?->toArray()];
    }
}
