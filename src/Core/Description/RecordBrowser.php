<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * The body of a record dialog: search and tree switch on top, the nodes on the left, their records on the right (D-805).
 *
 * ⚠️ *A flat choice — one node — shows no tree and no switch for it (D-1137).*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class RecordBrowser implements Describes
{
    /**
     * @param list<RecordBranch> $branches
     * @param list<RecordChoice> $first    Before every group: «nothing», and a kept record outside the tree (D-360).
     */
    public function __construct(
        public readonly array $branches,
        public readonly array $first,
        /** Named to a screen reader as the search field's subject. */
        public readonly string $rootName,
        public readonly string $searchWord,
        /** Empty: no tree switch. */
        public readonly string $treeWord,
        public readonly bool $flat,
        /** Stands as a dialog's body, not as a template the page shares (D-866). */
        public readonly bool $inDialog = true,
    ) {
    }

    public function asTemplate(): self
    {
        return new self($this->branches, $this->first, $this->rootName, $this->searchWord, $this->treeWord, $this->flat, false);
    }

    public function toArray(): array
    {
        return [
            'records'     => array_map(static fn (RecordBranch $b): array => $b->toArray(), $this->branches),
            'first'       => array_map(static fn (RecordChoice $c): array => $c->toArray(), $this->first),
            'root'        => $this->rootName,
            'search_word' => $this->searchWord,
            'tree_word'   => $this->treeWord,
            'flat'        => $this->flat,
            'in_dialog'   => $this->inDialog,
        ];
    }
}
