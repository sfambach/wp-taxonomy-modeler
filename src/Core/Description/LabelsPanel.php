<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\LabelSlot;

/**
 * The labels of a node: the short ones side by side beside the language they are in, the long ones beneath, each shown or offered
 * for writing (D-363).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class LabelsPanel implements Describes
{
    /**
     * @param list<LabelSlot>       $slots
     * @param array<string, string> $carried What the form carries besides the labels.
     * @param list<Control>         $acts
     */
    public function __construct(
        public readonly array $slots,
        public readonly bool $editing,
        /** The language picker's title and the picker; `null`: no picker. */
        public readonly ?string $localeTitle,
        public readonly Describes|string|null $localePicker,
        /** The page's form the fields belong to; empty: a form of their own. */
        public readonly string $formId,
        /** Where they are written; `null`: not written at all. */
        public readonly ?string $action,
        public readonly array $carried,
        public readonly array $acts,
    ) {
    }

    public function toArray(): array
    {
        // In reading order (A22): the locale first, then the short slots in one line, the long ones beneath.
        return [
            'labels'        => $this->localeTitle,
            'locale_picker' => $this->localePicker instanceof Describes ? $this->localePicker->toArray() : $this->localePicker,
            'short'         => array_map(static fn (LabelSlot $s): array => get_object_vars($s), array_values(array_filter($this->slots, static fn (LabelSlot $s): bool => ! $s->isLong))),
            'long'          => array_map(static fn (LabelSlot $s): array => get_object_vars($s), array_values(array_filter($this->slots, static fn (LabelSlot $s): bool => $s->isLong))),
            'editing'       => $this->editing,
            'form'          => $this->formId,
            'action'        => $this->action,
            'carried'       => $this->carried,
            'acts'          => array_map(static fn (Control $c): array => $c->toArray(), $this->acts),
        ];
    }
}
