<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Model\SimpleType;

/**
 * Eine Fassungsnummer.
 *
 * ⚠️ Sie verdient ihren Platz durch die **Ordnung** ([D-321](../../../../docs/NewConcept/90-decision-log.md)):
 * `1.10` kommt *nach* `1.9`, wo ein Text sie davor einsortieren würde und jede sortierte Liste still
 * falsch wäre.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class VersionType extends SpecialisedType
{
    /**
     * Wie viele Ebenen die Nummer hat: 1 → «1», 2 → «1.1», 3 → «1.1.1» — *sein Wort am 2026-09-12: «ich hätte gerne einen
     * simplen typ version, in ihm kann man einstellen wieviele ebenen also eine = 1 zwei = 1.1 … soll eine einstellung
     * sein»* ([D-738](../../../docs/NewConcept/90-decision-log.md)). Ob der Typ «Version» heissen soll, ist offen (INF-043).
     */
    #[\Taxmod\Core\Model\NodeClass\Attribut]
    public int $levels = 3;

    public function type(): SimpleType
    {
        return SimpleType::Version;
    }

    public function nodeName(): string
    {
        return 'Version';
    }

    public function column(): string
    {
        return 'value_text';
    }

    /** ⚠️ *Drei Zahlen — `MAJOR.MINOR.PATCH` (`CD-11`). `1.2` ist keine Fassung dieses Projekts.* */
    public function wellFormedShape(): ?string
    {
        return self::shapeFor($this->levels);
    }

    public function wellFormedShapeWith(array $settings): ?string
    {
        return self::shapeFor(($settings['levels'] ?? null)?->value->int ?? $this->levels);
    }

    /** `1` bei einer Ebene, `1.1` bei zwei — je Ebene eine Zahl, dazwischen ein Punkt. */
    public static function shapeFor(int $levels): string
    {
        return '/^\d+(\.\d+){' . max(0, $levels - 1) . '}$/';
    }
}
