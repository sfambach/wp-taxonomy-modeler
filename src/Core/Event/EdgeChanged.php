<?php declare(strict_types=1);

namespace Taxmod\Core\Event;

/**
 * An edge changed — a field added, renamed, retargeted, hidden, removed, or a setting at it.
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
final class EdgeChanged extends ModelChanged
{
    public function ownerKind(): string
    {
        return 'relation';
    }
}
