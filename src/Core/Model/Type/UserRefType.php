<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Model\SimpleType;

/**
 * Ein Verweis auf einen WordPress-Benutzer.
 *
 * ⚠️ Als **Text** gespeichert, wie jeder undurchsichtige Schlüssel eines fremden Systems (`P4d`) —
 * der Kern sieht eine Zeichenkette und weiss nichts von WordPress
 * ([D-171](../../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ **Er ist der Typ ohne Renderer**, und genau das hat [D-484](../../../../docs/NewConcept/90-decision-log.md)
 * am 2026-08-28 gemessen: *im Aufzählungstyp, gesät, und nichts zeichnet ihn.* **Der Befund war der
 * Beleg für diese Klassen** — eine Liste an einer Stelle hätte ihn gezeigt.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class UserRefType extends SpecialisedType
{
    public function type(): SimpleType
    {
        return SimpleType::UserRef;
    }

    public function nodeName(): string
    {
        return 'User reference';
    }

    public function humanName(): string
    {
        return 'user reference';
    }

    public function column(): string
    {
        return 'value_text';
    }
}
