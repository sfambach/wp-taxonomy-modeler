<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * A value a person can change. `name` and `form` are the contract with {@see \Taxmod\Core\Renderer\Submission}, which reads
 * the value back — not formatting (D-1429 A14).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class ValueInput implements Describes
{
    public function __construct(
        public readonly InputKind $kind,
        public readonly string $name,
        public readonly string $value,
        /** The form it belongs to when it stands outside it, a table cell beside the cell with the form. */
        public readonly string $form = '',
        public readonly bool $required = false,
        /** Width in characters (D-659): a wish, never a limit. */
        public readonly ?string $size = null,
        public readonly ?string $pattern = null,
        public readonly ?string $inputMode = null,
        public readonly ?string $min = null,
        public readonly ?string $max = null,
        public readonly ?string $step = null,
        public readonly ?string $rows = null,
        public readonly ?string $columns = null,
        /** The language of code; why a fixed value cannot be changed here. */
        public readonly string $note = '',
        /** What is shown where it differs from what is sent: a fixed value's name, a range's converted value. */
        public readonly ?string $shown = null,
        /** D-1501: the id a page's label points at (`for`), and the grey hint inside an empty box. */
        public readonly string $id = '',
        public readonly string $placeholder = '',
        /** D-1504: a Tick that stands ticked. */
        public readonly bool $checked = false,
        /**
         * D-1504: a Choice's possibilities, value → word, in the order shown.
         *
         * @var array<int|string, string>
         */
        public readonly array $choices = [],
        /**
         * D-1504: values of a Choice that are shown but cannot be taken.
         *
         * @var list<int|string>
         */
        public readonly array $locked = [],
        /** D-1504: the id of a datalist that suggests values for a Line. */
        public readonly string $suggestions = '',
        /** D-1504: a Choice with each possibility on its own line. */
        public readonly bool $lines = false,
        /** D-1504: the name a screen reader says for a box without a visible label of its own (a table cell). */
        public readonly string $ariaLabel = '',
    ) {
    }

    public function toArray(): array
    {
        return [
            'input'      => $this->kind->value,
            'name'       => $this->name,
            'value'      => $this->value,
            'form'       => $this->form,
            'required'   => $this->required,
            'size'       => $this->size,
            'pattern'    => $this->pattern,
            'input_mode' => $this->inputMode,
            'min'        => $this->min,
            'max'        => $this->max,
            'step'       => $this->step,
            'rows'       => $this->rows,
            'columns'    => $this->columns,
            'note'       => $this->note,
            'shown'      => $this->shown,
            'id'         => $this->id,
            'placeholder' => $this->placeholder,
            'checked'    => $this->checked,
            'choices'    => $this->choices,
            'locked'     => $this->locked,
            'suggestions' => $this->suggestions,
            'lines'      => $this->lines,
            'aria_label' => $this->ariaLabel,
        ];
    }
}
