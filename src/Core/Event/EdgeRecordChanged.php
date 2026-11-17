<?php declare(strict_types=1);

namespace Taxmod\Core\Event;

/**
 * A value of a record at one edge changed.
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
final class EdgeRecordChanged extends ModelChanged
{
    public function ownerKind(): string
    {
        return 'record_value';
    }
}
