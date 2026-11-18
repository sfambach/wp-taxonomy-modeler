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
    ) {
    }

    public static function inDialog(DialogBox $dialog, bool $taken, ?string $href, string $openWord, string $clearWord): self
    {
        return new self($dialog, $taken, $href, $openWord, $clearWord);
    }

    /** ⚠️ *The tree beside the search carries no tree: the script fetches nodes and records when it is pressed (D-1011).* */
    public static function bySearch(string $name, string $form, ?int $chosen, string $word, ?string $href, array $words): self
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
            (string) ($words['tree'] ?? '')
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
        ];
    }
}
