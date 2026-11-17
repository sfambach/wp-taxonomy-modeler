<?php declare(strict_types=1);

namespace Taxmod\Core\Event;

/**
 * An event that carries a name besides its class — «control pressed, name `save_record`» — so a listener subscribes to the
 * pair and not to every control (plan §2).
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
interface Named
{
    public function name(): string;
}
