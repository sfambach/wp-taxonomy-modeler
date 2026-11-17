<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * A piece of a page description that writes itself as plain data and is read back from it — the JSON form exists only where
 * the description leaves the process ([D-628](../../../docs/NewConcept/90-decision-log.md),
 * [D-629](../../../docs/NewConcept/90-decision-log.md)).
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
interface Describes
{
    /** @return array<string, mixed> scalars, null, lists and maps only — what `json_encode` writes without loss */
    public function toArray(): array;
}
