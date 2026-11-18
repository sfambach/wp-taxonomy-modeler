<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * A node's page: its blocks in the order of their slots, each under its title, a collapsed one folded (R20a).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class PageView implements Describes
{
    /** @param list<array{title: string, body: Describes|string, collapsed: bool}> $blocks */
    public function __construct(public readonly array $blocks)
    {
    }

    public function toArray(): array
    {
        return ['page' => array_map(static fn (array $b): array => ['title' => $b['title'], 'body' => $b['body'] instanceof Describes ? $b['body']->toArray() : $b['body'], 'collapsed' => $b['collapsed']], $this->blocks)];
    }
}
