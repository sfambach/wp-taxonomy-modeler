<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * Where a field's dialog body stands only once on the page: the key of the template, the field it is for, and the current
 * choice (D-866).
 *
 * ⚠️ *The current choice stands in the placeholder as a chosen, unseen button, so a save without a script loses nothing.*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class SharedBody implements Describes
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $form,
        public readonly string $value,
        /** @var list<Describes> The current choice and what else must be saved without a script. */
        public readonly array $kept = [],
        /** A record dialog's placeholder, which stands as the dialog's body; otherwise a node chooser's. */
        public readonly bool $ofRecords = false,
    ) {
    }

    public function toArray(): array
    {
        return ['shared_body' => $this->key, 'name' => $this->name, 'form' => $this->form, 'value' => $this->value, 'kept' => array_map(static fn (Describes $d): array => $d->toArray(), $this->kept), 'records' => $this->ofRecords];
    }
}
