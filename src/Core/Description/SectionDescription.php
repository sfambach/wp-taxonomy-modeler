<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * A section of a page — the tree, the fields, the settings, the records — with its title, whether it starts folded, and its
 * rows (plan §3). No markup: where `Section::$body` is a string today, this holds rows.
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
final class SectionDescription implements Describes
{
    /** @param list<RowDescription> $rows */
    public function __construct(
        public readonly string $title,
        public readonly bool $folded = false,
        public readonly array $rows = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'title'  => $this->title,
            'folded' => $this->folded,
            'rows'   => array_map(static fn (RowDescription $r): array => $r->toArray(), $this->rows),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['title'],
            (bool) $data['folded'],
            array_map(static fn (array $r): RowDescription => RowDescription::fromArray($r), (array) $data['rows']),
        );
    }
}
