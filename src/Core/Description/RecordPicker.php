<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * The choice of one record for a field: in a dialog (D-791), or by a search the edge answers (D-958), beside it the chosen record
 * to open (D-1255) and the bin that clears the choice (D-1011, D-1138).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class RecordPicker implements Describes
{
    private function __construct(
        public readonly ?DialogBox $dialog,
        public readonly bool $taken,
        public readonly ?string $href,
        public readonly string $openWord,
        public readonly string $clearWord,
        public readonly string $name = '',
        public readonly string $form = '',
        public readonly ?int $chosen = null,
        public readonly string $word = '',
        public readonly string $searchWord = '',
        public readonly string $treeWord = '',
        public readonly bool $wrap = false,
        public readonly string $opens = '',
        public readonly int $under = 0,
    ) {
    }

    public static function inDialog(DialogBox $dialog, bool $taken, ?string $href, string $openWord, string $clearWord): self
    {
        return new self($dialog, $taken, $href, $openWord, $clearWord);
    }

    /**
     * A node choice in a dialog that may stay empty, in the same shell as the record choice: the bin clears it (D-851, D-1511 rule 3).
     * The name and form carry the empty radio the bin checks — the node tree has none of its own.
     */
    public static function aroundNodeDialog(DialogBox $dialog, bool $taken, string $clearWord, string $name, string $form): self
    {
        return new self($dialog, $taken, null, '', $clearWord, $name, $form);
    }

    /**
     * ⚠️ *The tree beside the search carries no tree: the script fetches nodes and records when it is pressed (D-1011).*
     *
     * @param array<string, string> $words open, clear, search, tree
     * @param bool   $wrap  the chosen word breaks into lines instead of ending in «…» (D-1448)
     * @param string $opens the id of a dialog switch the chosen word opens — a module's own view of the record (D-1111, D-1488)
     * @param int    $under the node the edge searches under — 0 is all of `Model` (D-958), otherwise the field's target (D-1587)
     */
    public static function bySearch(string $name, string $form, ?int $chosen, string $word, ?string $href, array $words, bool $wrap = false, string $opens = '', int $under = 0): self
    {
        return new self(
            null,
            $chosen !== null,
            $href,
            (string) ($words['open'] ?? ''),
            (string) ($words['clear'] ?? ''),
            $name,
            $form,
            $chosen,
            $word,
            (string) ($words['search'] ?? ''),
            (string) ($words['tree'] ?? ''),
            $wrap,
            $opens,
            $under
        );
    }

    public function toArray(): array
    {
        return [
            'record_picker' => $this->dialog?->toArray(),
            'taken'         => $this->taken,
            'href'          => $this->href,
            'open_word'     => $this->openWord,
            'clear_word'    => $this->clearWord,
            'name'          => $this->name,
            'form'          => $this->form,
            'chosen'        => $this->chosen,
            'word'          => $this->word,
            'search_word'   => $this->searchWord,
            'tree_word'     => $this->treeWord,
            'wrap'          => $this->wrap,
            'opens'         => $this->opens,
            'under'         => $this->under,
        ];
    }
}
