<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * One link of the extra-function chain: its name, its own fields, moving up and down, removing.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class AddonEntry implements Describes
{
    /** @param list<AddonField> $fields */
    public function __construct(
        /** The extra function's own name — what is submitted. */
        public readonly string $addon,
        public readonly string $title,
        /** The address the link submits under. */
        public readonly string $name,
        public readonly string $form,
        public readonly array $fields,
        public readonly bool $first,
        public readonly bool $last,
    ) {
    }

    public function toArray(): array
    {
        return [
            'addon_entry' => $this->addon,
            'title'       => $this->title,
            'name'        => $this->name,
            'form'        => $this->form,
            'fields'      => array_map(static fn (AddonField $f): array => $f->toArray(), $this->fields),
            'first'       => $this->first,
            'last'        => $this->last,
        ];
    }
}
