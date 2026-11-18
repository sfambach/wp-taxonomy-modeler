<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * One record to tick in a record dialog: its number, its word, the text a search finds it by (D-791, D-805).
 *
 * ⚠️ *One of four ways: the one record of a field (`one`), several to add as rows (`several`), rows of a reference list that send
 * nothing themselves (`forRows`), and the record in a shared template, which has no name until the script copies it (`inTemplate`).*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class RecordChoice implements Describes
{
    private function __construct(
        /** The record's number; empty: «nothing». */
        public readonly string $value,
        public readonly string $word,
        public readonly bool $several,
        public readonly ?string $name,
        public readonly string $form,
        public readonly bool $chosen,
        public readonly bool $forRows,
        public readonly string $search = '',
        /** Found by a search the page was opened with. */
        public readonly bool $match = false,
    ) {
    }

    public static function one(string $name, string $form, string $value, bool $chosen, string $word, string $search = '', bool $match = false): self
    {
        return new self($value, $word, false, $name, $form, $chosen, false, $search, $match);
    }

    public static function several(string $name, string $form, string $value, string $word, string $search = '', bool $match = false): self
    {
        return new self($value, $word, true, $name, $form, false, false, $search, $match);
    }

    public static function forRows(string $value, string $word, string $search = '', bool $match = false): self
    {
        return new self($value, $word, true, null, '', false, true, $search, $match);
    }

    public static function inTemplate(string $value, string $word, string $search = '', bool $match = false): self
    {
        return new self($value, $word, false, null, '', false, false, $search, $match);
    }

    public function toArray(): array
    {
        return [
            'record'   => $this->value,
            'word'     => $this->word,
            'several'  => $this->several,
            'name'     => $this->name,
            'form'     => $this->form,
            'chosen'   => $this->chosen,
            'for_rows' => $this->forRows,
            'search'   => $this->search,
            'match'    => $this->match,
        ];
    }
}
