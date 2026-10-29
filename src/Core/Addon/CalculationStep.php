<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

use Taxmod\Core\Model\NodeClass\Attribut;
use Taxmod\Core\Model\NodeClass\FieldSource;

/**
 * Ein Rechenschritt an einem Feld: das Feld wird aus anderen Feldern desselben Satzes gerechnet statt eingegeben
 * ([D-1022](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *Sein Wort: «Lieferkosten und Steuern anteilig als Feld … und daraus dann den Einstandspreis berechnest». Mehrere Schritte an
 * derselben Kante sind die Formel, gelesen von oben nach unten wie ein Rechenstreifen — `+ Preis gesamt`, `+ Versand anteilig`,
 * `÷ Anzahl`. Der erste Schritt beginnt bei null.*
 *
 * ```mermaid
 * flowchart LR
 *   S["Schritte der Kante, in ihrer Reihenfolge"] --> R["0 + a + b ÷ c"]
 *   R --> W["Feld geschrieben, nur wenn es sich ändert"]
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md D-1022
 */
final class CalculationStep implements Addon
{
    public const NAME = 'calculate';

    #[Attribut]
    public CalculationOperation $operation = CalculationOperation::Plus;

    /** Das Feld des Satzes, mit dem gerechnet wird — nur Felder des Knotens, von dem die Kante ausgeht. */
    #[Attribut(refersTo: 'relation', fieldsFrom: FieldSource::Owner)]
    public ?int $field = null;

    public function name(): string
    {
        return self::NAME;
    }

    public function sites(): array
    {
        return [AddonSite::Edge];
    }

    public function requirements(): array
    {
        return [];
    }
}
