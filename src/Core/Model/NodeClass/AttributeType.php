<?php declare(strict_types=1);

namespace Taxmod\Core\Model\NodeClass;

/**
 * Die sieben Typen, die ein Attribut haben kann (Anforderung 3.1.1) — nichts sonst.
 *
 * ⚠️ *Kein Datum, kein Datensatz: die Typen einer Einstellung sind `bool`, `int`, `decimal`,
 * `text`, Enum, Verweis auf einen Knoten, Objekt einer Wertklasse. Was `date` bräuchte, ist ein
 * Feld, keine Einstellung.*
 *
 * @see docs/einstellungen-anforderungen.md
 */
enum AttributeType: string
{
    case Bool    = 'bool';
    case Int     = 'int';
    case Decimal = 'decimal';
    case Text    = 'text';
    // ⚠️ *Ein Datum als Einstellung — `min`, `max` am Typ «Date and time» (D-757); abgelegt als Text, gezeichnet als Datum.*
    case Date    = 'date';
    case Enum    = 'enum';
    case NodeRef = 'node';
    // ⚠️ *Verweis auf ein Feld des Knotens, an dem die Einstellung steht — Anforderung 3.1.1, erweitert mit D-752.*
    case RelationRef = 'relation';
    case Object  = 'object';

    /** Ob ein Wert dieses Typs eine eigene Zeile ist (alles ausser Objekt) oder ein Einstellungsobjekt. */
    public function isScalar(): bool
    {
        return $this !== self::Object;
    }
}
