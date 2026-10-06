<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

use Taxmod\Core\Model\NodeClass\Attribut;
use Taxmod\Core\Model\NodeClass\FieldSource;

/**
 * «Mehrere hinzufügen» an einer mehrfachen Kante ([D-806](../../../docs/NewConcept/90-decision-log.md),
 * [D-845](../../../docs/NewConcept/90-decision-log.md)) — die zweite Zusatzfunktion.
 *
 * *Sein Wort damals: «the item list needs to know what the selection criteria is to create new item and propose a button to add
 * multiple». Ohne gewählte Funktion kein Knopf.*
 */
final class PickRowsAddon implements PicksRows
{
    public const NAME = 'pick_rows';

    /** Das Feld der neuen Zeile, über das gewählt wird — etwa `Part` an einer Position. */
    #[Attribut(refersTo: 'relation', fieldsFrom: FieldSource::Target)]
    public ?int $pick_field = null;

    public function name(): string
    {
        return self::NAME;
    }

    public function sites(): array
    {
        return [AddonSite::ManyEdge];
    }

    public function requirements(): array
    {
        return [];
    }

    public function pickField(array $settings): ?int
    {
        return ($settings['pick_field'] ?? null)?->reference;
    }
}
