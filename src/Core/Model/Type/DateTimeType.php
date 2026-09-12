<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Ein Typ für Datum, Uhrzeit und beides zusammen, mit einer Genauigkeitseinstellung
 * ([D-291](../../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ In UTC gespeichert und in der Zeitzone der Seite gezeigt — ausser einem blossen Datum, das gar
 * keine Zeitzone hat.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class DateTimeType extends SpecialisedType
{
    /** Das Datum, an dem eine Uhrzeit ohne eigenes Datum geparkt wird. */
    public const TIME_WITHOUT_A_DATE = '1970-01-01';

    public function type(): SimpleType
    {
        return SimpleType::DateTime;
    }

    public function nodeName(): string
    {
        return 'Date and time';
    }

    public function humanName(): string
    {
        return 'date and time';
    }

    public function column(): string
    {
        return 'value_date';
    }

    /** ⚠️ *Ein Zeitpunkt ist vergleichbar, also ergibt eine Grenze für ihn einen Sinn.* */
    public function hasBounds(): bool
    {
        return true;
    }

    public function valueFrom(string $characters): TypedValue
    {
        return TypedValue::ofDate($this->timestamp($characters));
    }

    /**
     * Die drei Formen, die ein Datumsfeld schickt, auf das gebracht, was die Spalte hält.
     *
     * ⚠️ **Eine Uhrzeit ohne Datum wird gegen die Epoche gespeichert, und das ist ein Kompromiss,
     * kein Entwurf.** *Die Spalte ist ein `datetime` (D-291 gibt Datum, Uhrzeit und beides einem
     * Typ), also hat eine Tageszeit ohne Datum keinen Platz. Siehe
     * [OQ-088](../../../../docs/NewConcept/91-open-questions.md).*
     */
    private function timestamp(string $characters): string
    {
        $characters = str_replace('T', ' ', $characters);

        return match (true) {
            // ⚠️ *Nur Jahr, nur Monat und Jahr — die Genauigkeiten aus D-737; gespeichert wird der erste Tag, die erste Stunde.*
            preg_match('/^\d{4}$/', $characters) === 1
                => $characters . '-01-01 00:00:00',
            preg_match('/^\d{4}-\d{2}$/', $characters) === 1
                => $characters . '-01 00:00:00',
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $characters) === 1
                => $characters . ' 00:00:00',
            preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $characters) === 1
                => self::TIME_WITHOUT_A_DATE . ' ' . $this->withSeconds($characters),
            preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}(:\d{2})?$/', $characters) === 1
                => substr($characters, 0, 10) . ' ' . $this->withSeconds(substr($characters, 11)),
            default
                => throw $this->refuse($characters),
        };
    }

    private function withSeconds(string $time): string
    {
        return strlen($time) === 5 ? $time . ':00' : $time;
    }
}
