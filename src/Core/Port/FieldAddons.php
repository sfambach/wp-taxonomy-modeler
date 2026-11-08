<?php declare(strict_types=1);

namespace Taxmod\Core\Port;

use Taxmod\Core\Model\Relation;

/**
 * Which add-ons are chosen at a set of fields — the one question the write path asks of the settings, so it can tell a
 * variant's own fields from its parent's (D-1208).
 *
 * Narrow on purpose, like {@see Presets}: {@see \Taxmod\Core\Service\DataEntry} must not depend on the drawing.
 *
 * @see docs/pakete/varianten/plan.md
 */
interface FieldAddons
{
    /**
     * @param  list<Relation> $relations
     * @return array<int, list<string>> relation id → add-on names; fields without one are left out
     */
    public function addonNamesAt(array $relations): array;
}
