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
        return '/^\d+\.\d+\.\d+$/';
    }
}
