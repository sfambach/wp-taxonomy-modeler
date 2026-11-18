<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * One node of a record dialog's tree with its records — a branch to open on the left, a group of choices on the right (D-805).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class RecordBranch implements Describes
{
    /** @param list<RecordChoice> $choices */
    public function __construct(
        public readonly int $depth,
        public readonly string $name,
        public readonly bool $open,
        public readonly array $choices,
        /** How many records it holds; none shows no count. */
        public readonly int $count = 0,
        /** Where a missing record is made at this node (D-792); empty: nowhere. */
        public readonly string $addAt = '',
        public readonly string $addWord = '',
        /** The node a record typed here is made at (D-1171); 0: none. */
        public readonly int $newAt = 0,
        public readonly string $newWord = '',
        public readonly string $createWord = '',
    ) {
    }

    public function toArray(): array
    {
        return [
            'branch'      => $this->name,
            'depth'       => $this->depth,
            'open'        => $this->open,
            'choices'     => array_map(static fn (RecordChoice $c): array => $c->toArray(), $this->choices),
            'count'       => $this->count,
            'add_at'      => $this->addAt,
            'add_word'    => $this->addWord,
            'new_at'      => $this->newAt,
            'new_word'    => $this->newWord,
            'create_word' => $this->createWord,
        ];
    }
}
