<?php declare(strict_types=1);

namespace Taxmod\Core\Event;

/**
 * A record changed as a whole — created, moved, removed.
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
final class NodeRecordChanged extends ModelChanged
{
    public function ownerKind(): string
    {
        return 'record';
    }
}
