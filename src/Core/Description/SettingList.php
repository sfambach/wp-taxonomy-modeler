<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * The links of a list setting beneath its chooser: per link a switch «on» and its position; links not set here are marked inherited.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class SettingList implements Describes
{
    /** @param list<array{name: string, word: string, active: bool, position: int, inherited: bool}> $entries */
    public function __construct(
        public readonly string $form,
        public readonly array $entries,
    ) {
    }

    public function toArray(): array
    {
        return ['setting_list' => $this->entries, 'form' => $this->form];
    }
}
