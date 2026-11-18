<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * Pieces that stand one after another with nothing around them.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class Sequence implements Describes
{
    /** @var list<Describes> */
    public readonly array $pieces;

    public function __construct(Describes ...$pieces)
    {
        $this->pieces = array_values($pieces);
    }

    public function toArray(): array
    {
        return ['sequence' => array_map(static fn (Describes $p): array => $p->toArray(), $this->pieces)];
    }
}
