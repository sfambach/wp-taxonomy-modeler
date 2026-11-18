<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * Several fields in one line or one column, each with or without its label (D-471); the hints of a line gathered behind it (D-662).
 *
 * ⚠️ *Shown without labels and nothing in any field: an empty value, not an empty frame — whether a field shows anything is a question
 * about what it draws, so the drawer answers it.*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class CompactView implements Describes
{
    /** @param list<array{name: string, field: Describes, hint: string}> $parts */
    public function __construct(
        public readonly array $parts,
        public readonly bool $vertical,
        public readonly bool $withLabel,
        /** Showing, unlabelled: when no field shows anything, an empty value stands instead. */
        public readonly bool $emptyIsAValue,
        /** The hints of a line, gathered behind it; `null`: none. */
        public readonly ?Hint $gathered = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'compact'    => array_map(static fn (array $p): array => ['name' => $p['name'], 'field' => $p['field']->toArray(), 'hint' => $p['hint']], $this->parts),
            'vertical'   => $this->vertical,
            'with_label' => $this->withLabel,
            'empty'      => $this->emptyIsAValue,
            'gathered'   => $this->gathered?->toArray(),
        ];
    }
}
