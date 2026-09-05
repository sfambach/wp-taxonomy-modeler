<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Model\SimpleType;

/**
 * Eine Adresse, mit einem Renderer, der sie als `mailto:` anklickbar macht
 * ([D-322](../../../../docs/NewConcept/90-decision-log.md)).
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class EmailType extends SpecialisedType
{
    public function type(): SimpleType
    {
        return SimpleType::Email;
    }

    public function nodeName(): string
    {
        return 'Email';
    }

    public function column(): string
    {
        return 'value_text';
    }

    /**
     * ⚠️ *Bewusst genügsam: ein Zeichen vor dem `@`, ein Name mit mindestens einem Punkt danach,
     * keine Leerzeichen. **Die vollständige Adressgrammatik zu prüfen wäre ein Fehler** — sie erlaubt
     * Dinge, die jeder für falsch hält, und verbietet Dinge, die zustellbar sind.*
     */
    public function wellFormedShape(): ?string
    {
        return '/^[^@\s]+@[^@\s.]+(?:\.[^@\s.]+)+$/';
    }
}
