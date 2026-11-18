<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * A field that refers to several records: a line per reference, each with «insert» and «remove», and beside it the record dialog
 * whose ticks become lines (D-1078).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class ReferenceList implements Describes
{
    /** @param list<Choosing> $lines */
    public function __construct(
        /** The one name every line submits under; an empty one stands first, so removing the last line still writes. */
        public readonly string $name,
        public readonly string $form,
        public readonly array $lines,
        public readonly string $insertWord,
        public readonly string $removeWord,
        /** The record dialog; `null`: none. */
        public readonly ?RecordBrowser $browser = null,
        public readonly string $fieldName = '',
        public readonly string $searchWord = '',
        public readonly string $okWord = '',
        public readonly string $cancelWord = '',
    ) {
    }

    public function toArray(): array
    {
        return [
            'reference_list' => array_map(static fn (Choosing $c): array => $c->toArray(), $this->lines),
            'name'           => $this->name,
            'form'           => $this->form,
            'insert_word'    => $this->insertWord,
            'remove_word'    => $this->removeWord,
            'browser'        => $this->browser?->toArray(),
            'field_name'     => $this->fieldName,
            'search_word'    => $this->searchWord,
            'ok_word'        => $this->okWord,
            'cancel_word'    => $this->cancelWord,
        ];
    }
}
