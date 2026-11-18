<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

use Taxmod\Core\Renderer\Control;

/**
 * One field of a node as a row of the field table: its name, target, kind, origin, multiplicity, switches, value and acts, and
 * under it the fold and the field's settings (D-392, D-689).
 *
 * ⚠️ *Scaffolding (A15): the parts that arrive as sections are still drawn markup where the part that made them still builds it.*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class FieldRow implements Describes
{
    /** @param list<Control> $acts */
    public function __construct(
        public readonly int $relation,
        public readonly string $name,
        /** Where a rename is written; `null`: the name is only shown. */
        public readonly ?string $renameAs,
        public readonly string $renameForm,
        public readonly string $target,
        public readonly ?string $targetHref,
        public readonly Describes|string $targetChooser,
        /** The kind's control; `null`: `kindText` stands as code. */
        public readonly ?Describes $kind,
        public readonly string $kindText,
        /** Its own field, not inherited. */
        public readonly bool $own,
        public readonly string $ownWord,
        public readonly string $inheritedWord,
        public readonly bool $locked,
        public readonly string $lockedWord,
        public readonly string $lockedReason,
        public readonly ?Describes $multiplicity,
        public readonly ?Describes $readOnly,
        public readonly ?Describes $unique,
        /** The «I override here» tick of the row; `null`: no column for it. */
        public readonly Describes|string|null $override,
        /** Whether the table has a value column at all. */
        public readonly bool $valueColumn,
        /** The value; `null` in a value column: nothing to show. */
        public readonly Describes|string|null $value,
        /** The form the row's acts post; empty: none. */
        public readonly string $actsForm,
        public readonly string $actsAt,
        /** @var array<string, string> */
        public readonly array $carried,
        public readonly array $acts,
        public readonly ?Control $fold,
        public readonly int $columns,
        public readonly string $settingsRow,
        /** The field's own settings beneath it; `null`: none. */
        public readonly Describes|string|null $settings,
        /** D-1453: where an inherited field is declared — the boundary's address and the owner's name; null on an own field. */
        public readonly ?string $ownerHref = null,
        public readonly string $ownerName = '',
    ) {
    }

    public function toArray(): array
    {
        $teil = static fn (Describes|string|null $d): mixed => $d instanceof Describes ? $d->toArray() : $d;

        return [
            'field_row'      => $this->relation,
            'name'           => $this->name,
            'rename_as'      => $this->renameAs,
            'rename_form'    => $this->renameForm,
            'target'         => $this->target,
            'target_href'    => $this->targetHref,
            'target_chooser' => $teil($this->targetChooser),
            'kind'           => $teil($this->kind),
            'kind_text'      => $this->kindText,
            'own'            => $this->own,
            'own_word'       => $this->ownWord,
            'inherited_word' => $this->inheritedWord,
            'locked'         => $this->locked,
            'locked_word'    => $this->lockedWord,
            'locked_reason'  => $this->lockedReason,
            'multiplicity'   => $teil($this->multiplicity),
            'read_only'      => $teil($this->readOnly),
            'unique'         => $teil($this->unique),
            'override'       => $teil($this->override),
            'value_column'   => $this->valueColumn,
            'value'          => $teil($this->value),
            'acts_form'      => $this->actsForm,
            'acts_at'        => $this->actsAt,
            'carried'        => $this->carried,
            'acts'           => array_map(static fn (Control $c): array => $c->toArray(), $this->acts),
            'fold'           => $this->fold?->toArray(),
            'columns'        => $this->columns,
            'settings_row'   => $this->settingsRow,
            'settings'       => $teil($this->settings),
            'owner_href'     => $this->ownerHref,
            'owner_name'     => $this->ownerName,
        ];
    }
}
