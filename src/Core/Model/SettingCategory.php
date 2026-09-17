<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * Die Gruppe, in der eine Einstellungszeile im Einstellungsbereich steht — nach ihrem Namen.
 *
 * ⚠️ **Seit Schritt 7 des Bauplans (2026-09-11) nach dem Attributnamen, nicht nach einer Aufzählung**
 * ([D-712](../../../docs/NewConcept/90-decision-log.md)): *die Einstellungen kommen aus dem Vertrag der
 * Klasse; was zeichnet und wandelt, ist «Anzeige», was Grenzen des Typs setzt, gehört «zum Typ», der
 * Rest sind «Regeln».*
 *
 * @see docs/einstellungen-anforderungen.md
 */
enum SettingCategory: string
{
    case Display = 'display';

    /**
     * Zum Typ: `min`, `max`, `step` — die Zeile heisst nach dem Typ des Gegenstands, und je Typ gibt es
     * eine Gruppe.
     */
    case OfTheType = 'of-the-type';

    case Rules = 'rules';

    private const DISPLAY    = ['renderer', 'converter', 'addons', 'icon'];
    private const OF_THE_TYPE = ['min', 'max', 'step'];

    public static function of(string $key, ?SimpleType $subject = null): self
    {
        if (in_array($key, self::DISPLAY, true)) {
            return self::Display;
        }

        if (in_array($key, self::OF_THE_TYPE, true)) {
            return $subject === null ? self::Rules : self::OfTheType;
        }

        return self::Rules;
    }

    public function label(?SimpleType $subject = null): string
    {
        if ($this === self::OfTheType && $subject !== null) {
            return $subject->value;
        }

        return $this->value;
    }
}
