<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

use Taxmod\Core\Renderer\Control;

/**
 * What stands behind the rows of a part: adding the first row (D-832), and adding several at once (D-806).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class PartAdd implements Describes
{
    public function __construct(
        /** `null`: every row carries its own «+» already. */
        public readonly ?Control $add,
        /** The record dialog with a tick per record; `null`: the edge names no field to choose by. */
        public readonly ?DialogBox $several,
    ) {
    }

    public function toArray(): array
    {
        return ['part_add' => $this->add?->toArray(), 'several' => $this->several?->toArray()];
    }
}
