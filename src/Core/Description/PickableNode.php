<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * A node as a chooser row: picked by its number under the field's name, or shown and not offered (D-159).
 *
 * ⚠️ *A row that cannot be picked stays in the tree, because a child of it may well be pickable.*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class PickableNode implements Describes
{
    public function __construct(
        public readonly NodeInTree $node,
        public readonly bool $offered,
        /** Empty in a shared template: the script names it when it copies the template into a field (D-866). */
        public readonly string $name = '',
        public readonly int $value = 0,
        public readonly bool $chosen = false,
        public readonly string $form = '',
        /** Stands in a placeholder only to keep the choice when a form is saved without a script. */
        public readonly bool $unseen = false,
    ) {
    }

    /** The same row in a shared template: no name, no form, nothing chosen, and the row id without the field. */
    public function neutral(string $rowPrefix): self
    {
        return new self(
            $this->node->rowPrefix === $rowPrefix ? $this->node->withRowPrefix(TreeView::FIELD_PLACEHOLDER) : $this->node,
            $this->offered,
            value: $this->value
        );
    }

    public function unseen(): self
    {
        return new self($this->node, $this->offered, $this->name, $this->value, $this->chosen, $this->form, true);
    }

    public function toArray(): array
    {
        return [
            'pick'    => $this->node->toArray(),
            'offered' => $this->offered,
            'name'    => $this->name,
            'value'   => $this->value,
            'chosen'  => $this->chosen,
            'form'    => $this->form,
            'unseen'  => $this->unseen,
        ];
    }
}
