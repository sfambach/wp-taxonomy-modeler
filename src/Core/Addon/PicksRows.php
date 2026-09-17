<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

use Taxmod\Core\Model\TypedValue;

/** Eine Zusatzfunktion, die beim Anlegen von Zeilen eingreift ([D-845](../../../docs/NewConcept/90-decision-log.md)). */
interface PicksRows extends Addon
{
    /**
     * Das Feld der neuen Zeile, über das sie gewählt wird — `null`, wo keines eingestellt ist.
     *
     * @param array<string, TypedValue> $settings Die eigenen Felder der gewählten Funktion.
     */
    public function pickField(array $settings): ?int;
}
