<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

use Taxmod\Core\Renderer\Control;

/**
 * One record of a node: its title, its form, in developer mode its diagnosis, and when editing the acts above it (D-366).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class RecordView implements Describes
{
    /**
     * @param array<string, string> $carried
     * @param list<Control>         $acts
     */
    public function __construct(
        public readonly string $title,
        public readonly Describes|string $form,
        public readonly Describes|string|null $diagnostic,
        /** Where it is written; `null`: not written here. */
        public readonly ?string $action,
        public readonly array $carried,
        public readonly array $acts,
    ) {
    }

    public function toArray(): array
    {
        $teil = static fn (Describes|string|null $d): mixed => $d instanceof Describes ? $d->toArray() : $d;

        return [
            'record'     => $this->title,
            'form'       => $teil($this->form),
            'diagnostic' => $teil($this->diagnostic),
            'action'     => $this->action,
            'carried'    => $this->carried,
            'acts'       => array_map(static fn (Control $c): array => $c->toArray(), $this->acts),
        ];
    }
}
