<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

use Taxmod\Core\Renderer\Control;

/**
 * One node as a row of a tree shows it: its name, its icon and class, where a click leads, and what may be done to it (D-367).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class NodeInTree implements Describes
{
    /** @param list<Control> $actions Done through a form posted to `$actsAt`; none without one. */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        /** Put before the id, so the same node can stand twice on a page. */
        public readonly string $rowPrefix,
        public readonly ?Icon $icon = null,
        public readonly string $classWord = '',
        public readonly ?string $href = null,
        public readonly bool $hidden = false,
        public readonly ?string $actsAt = null,
        public readonly ?Carried $carried = null,
        public readonly array $actions = [],
        /** The write count, shown only in developer mode (D-349); `null`: not shown. */
        public readonly ?int $writes = null,
    ) {
    }

    public function withRowPrefix(string $prefix): self
    {
        return new self($this->id, $this->name, $prefix, $this->icon, $this->classWord, $this->href, $this->hidden, $this->actsAt, $this->carried, $this->actions, $this->writes);
    }

    public function toArray(): array
    {
        return [
            'node'       => $this->id,
            'name'       => $this->name,
            'row_prefix' => $this->rowPrefix,
            'icon'       => $this->icon?->toArray(),
            'class'      => $this->classWord,
            'href'       => $this->href,
            'hidden'     => $this->hidden,
            'acts_at'    => $this->actsAt,
            'carried'    => $this->carried?->toArray(),
            'actions'    => array_map(static fn (Control $c): array => $c->toArray(), $this->actions),
            'writes'     => $this->writes,
        ];
    }
}
