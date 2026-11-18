<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * The tick «I override this here» in the first column of a setting row — ticked where an inherited entry was replaced automatically (D-688).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class OverrideTick implements Describes
{
    public function __construct(
        public readonly string $name,
        public readonly string $form,
        public readonly bool $ticked,
        public readonly string $title,
    ) {
    }

    public function toArray(): array
    {
        return ['override_tick' => $this->name, 'form' => $this->form, 'ticked' => $this->ticked, 'title' => $this->title];
    }
}
