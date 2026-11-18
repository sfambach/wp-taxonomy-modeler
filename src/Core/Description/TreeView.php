<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * A tree: its lines in walking order, flat, each with its depth, and the search field that belongs to it (D-367, D-694).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class TreeView implements Describes
{
    /** The row id in a shared template; the script puts the field back when it copies it (D-866). */
    public const FIELD_PLACEHOLDER = 'taxmod-choice-{feld}-';

    /** @param list<TreeLine> $lines */
    public function __construct(
        public readonly array $lines,
        public readonly string $searchName = '',
        public readonly string $searchValue = '',
        public readonly string $searchForm = '',
    ) {
    }

    /** The first line that can be picked, which names the field and the form of all of them. */
    public function firstPick(): ?PickableNode
    {
        foreach ($this->lines as $line) {
            if ($line->cell instanceof PickableNode && $line->cell->offered) {
                return $line->cell;
            }
        }

        return null;
    }

    public function chosen(): ?PickableNode
    {
        foreach ($this->lines as $line) {
            if ($line->cell instanceof PickableNode && $line->cell->offered && $line->cell->chosen) {
                return $line->cell;
            }
        }

        return null;
    }

    /** The same tree as a template several fields share: no row names its field (D-866). */
    public function neutral(string $rowPrefix): self
    {
        return new self(
            array_map(static fn (TreeLine $l): TreeLine => $l->cell instanceof PickableNode ? $l->with($l->cell->neutral($rowPrefix)) : $l, $this->lines),
            $this->searchName,
            $this->searchValue,
            $this->searchForm
        );
    }

    public function toArray(): array
    {
        return [
            'tree'   => array_map(static fn (TreeLine $l): array => $l->toArray(), $this->lines),
            'search' => ['name' => $this->searchName, 'value' => $this->searchValue, 'form' => $this->searchForm],
        ];
    }
}
