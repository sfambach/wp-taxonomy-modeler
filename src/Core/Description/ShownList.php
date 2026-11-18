<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * Several values of one field shown together, each for itself (D-859, D-865): references one beneath the other,
 * pictures side by side and files beneath — or, in a record table, only the cover picture and the count (D-878, D-1205).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class ShownList implements Describes
{
    /**
     * @param list<MediaLink|ShownValue> $items    One beneath the other.
     * @param list<MediaLink>            $pictures Side by side, before the items.
     */
    public function __construct(
        public readonly array $items,
        public readonly bool $media = false,
        public readonly array $pictures = [],
        /** Only the count of the values, after the first picture; `null`: all values. */
        public readonly ?int $count = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'shown_list' => array_map(static fn (Describes $d): array => $d->toArray(), $this->items),
            'media'      => $this->media,
            'pictures'   => array_map(static fn (MediaLink $d): array => $d->toArray(), $this->pictures),
            'count'      => $this->count,
        ];
    }
}
