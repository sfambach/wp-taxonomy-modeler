<?php declare(strict_types=1);

namespace Taxmod\Core\Event;

/**
 * A node changed — created, renamed, moved, parked, its settings or labels.
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
final class NodeChanged extends ModelChanged
{
    public function ownerKind(): string
    {
        return 'node';
    }
}
