<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Der Sprung — ein Feld, das nichts speichert, sondern zu einem anderen Knoten führt und dort die Sätze filtert.
 *
 * ⚠️ **Seine Form, 2026-09-13 ([D-769](../../../../docs/NewConcept/90-decision-log.md)):** *«das ist glaube ich eine neue
 * feld art springen mit filter ich springe zur kompatibilität und setzte filter von = aktulle zeile»* — und dazu: *«hier
 * müsste man dann angeben welches feld mit welchen verglichen werden soll beim filtern»*.
 *
 * ```mermaid
 * flowchart LR
 *   S["Satz #22799 (V20)"] -->|"Sprung «Kompatibilität»"| Z["Knoten Kompatibilität"]
 *   Z -->|"Filterfeld «von» = Quellwert"| F["nur Sätze mit von = #22799"]
 * ```
 *
 * ⚠️ *Gerechnet beim Zeichnen, nie gespeichert — wie der Weg ({@see PathType}). Die Adresse baut der Rand, weil der Kern
 * keine kennt ({@see \Taxmod\Core\Service\Rendering::withJumps()}).*
 *
 * @see docs/NewConcept/97-implementation-plan.md Zeile 144
 */
final class JumpType extends SpecialisedType
{
    /**
     * Der Knoten, zu dem gesprungen wird — das erste aktive Glied gilt.
     *
     * ⚠️ *Eine Liste und kein einzelner Verweis, gemessen am 2026-09-13: der Auflöser gibt einen einzelnen Verweis als **Wort**
     * weiter ({@see \Taxmod\Core\Service\SettingsResolver} `asWord()` — «Kompatibilität» statt der Nummer), eine Liste mit ihrer
     * Nummer. Der Sprung braucht die Nummer.*
     */
    #[\Taxmod\Core\Model\NodeClass\Attribut(listOf: 'node', refersTo: \Taxmod\Core\Model\NodeClass\Category::class)]
    public array $ziel = [];

    public const ZIEL = 'ziel';

    /** Das Feld **am Ziel**, nach dem gefiltert wird — das erste aktive Glied gilt. */
    #[\Taxmod\Core\Model\NodeClass\Attribut(listOf: 'relation', fieldsFrom: \Taxmod\Core\Model\NodeClass\FieldSource::ChosenTarget)]
    public array $filter_feld = [];

    public const FILTER_FELD = 'filter_feld';

    /** Das Feld **dieses Satzes**, dessen Wert eingesetzt wird — leer heisst: der Satz selbst («von = aktuelle Zeile»). */
    #[\Taxmod\Core\Model\NodeClass\Attribut(listOf: 'relation', fieldsFrom: \Taxmod\Core\Model\NodeClass\FieldSource::Owner)]
    public array $quell_feld = [];

    public const QUELL_FELD = 'quell_feld';

    public function type(): SimpleType
    {
        return SimpleType::Jump;
    }

    public function nodeName(): string
    {
        return 'Jump';
    }

    public function column(): string
    {
        return 'value_text';
    }

    /** Ein Sprung wird nie eingegeben und nie gespeichert. */
    public function valueFrom(string $characters): TypedValue
    {
        return TypedValue::nothing();
    }
}
