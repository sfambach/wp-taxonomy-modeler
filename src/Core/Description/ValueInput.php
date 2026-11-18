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
        ];
    }
}
