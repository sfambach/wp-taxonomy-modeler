<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Eine Zusatzfunktion, die beim Speichern prüft — der Validator «im weitergehenden Sinne» ([D-845](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *Sein Wort: «wenn du sagt wie ein validator ist es vielleicht ein validator im weitergehenden sinne?» — und «nimm den validator mit».
 * Die festen Prüfungen am Typ und am Feld (Anzahl, eindeutig) bleiben, wo sie sind; hier steht nur, was gewählt wird.*
 */
interface ChecksOnSave extends Addon
{
    /** @return list<SimpleType> Für welche Typen; leer heisst «für jeden». */
    public function handles(): array;

    /**
     * @param  array<string, TypedValue> $settings Was an der Stelle gilt — die Grenzen und Muster des Typs.
     * @return list<Complaint> Leer: der Wert darf gespeichert werden.
     */
    public function check(TypedValue $value, ?SimpleType $type, array $settings): array;
}
