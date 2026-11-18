<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\Submission;

/**
 * A form of its own that holds only buttons — the acts of one row or record, sent where `submits` says.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class ActsForm implements Describes
{
    /** @param list<Control> $controls words among them are not drawn */
    public function __construct(
        public readonly string $id,
        public readonly Submission $submits,
        public readonly array $controls,
    ) {
    }

    public function toArray(): array
    {
        return [
            'acts_form' => $this->id,
            'action'    => $this->submits->action,
            'carried'   => $this->submits->hidden,
            'controls'  => array_map(static fn (Control $c): array => $c->toArray(), $this->controls),
        ];
    }
}
