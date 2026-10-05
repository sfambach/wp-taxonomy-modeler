<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Model\SimpleType;

/**
 * Ein Farbwert.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class ColorType extends SpecialisedType
{
    public function type(): SimpleType
    {
        return SimpleType::Color;
    }

    public function nodeName(): string
    {
        return 'Color';
    }

    public function column(): string
    {
        return 'value_text';
    }

    /**
     * ⚠️ *Sechs Stellen mit `#`. Die Kurzform `#f00` ist absichtlich **nicht** erlaubt: sie ist eine
     * zweite Schreibweise für denselben Wert, und zwei Schreibweisen in einer Spalte machen jeden
     * Vergleich zweideutig.*
     *
     * ⚠️ *Dieselbe Form kennt {@see \Taxmod\Core\Renderer\ColorRenderer} auch — dort entscheidet sie,
     * **ob der Farbwähler den Wert halten kann**, hier, **ob er richtig ist**.*
     */
    public function wellFormedShape(): ?string
    {
        return '/^#[0-9a-fA-F]{6}$/';
    }
}
