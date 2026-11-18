<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * The head of a node's page: a row per block — the node, the system — its title on the left (R20a).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class HeadView implements Describes
{
    /** @param list<array{key: string, title: string, body: Describes|string}> $rows */
    public function __construct(public readonly array $rows)
    {
    }

    public function toArray(): array
    {
        return ['head' => array_map(static fn (array $r): array => ['key' => $r['key'], 'title' => $r['title'], 'body' => $r['body'] instanceof Describes ? $r['body']->toArray() : $r['body']], $this->rows)];
    }
}
