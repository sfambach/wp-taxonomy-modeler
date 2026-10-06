<?php declare(strict_types=1);

namespace Taxmod\Core\Model\Type;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;

/**
 * Die Zusammenfassung — ein Feld, das andere Felder desselben Satzes zu einem Text zusammensetzt, gespeichert
 * und bei jeder Änderung neu geschrieben, nicht eingebbar.
 *
 * ⚠️ **Seine Form, 2026-09-20 ([D-885](../../../../docs/NewConcept/90-decision-log.md)):** *«warum speichern wir
 * nicht das Zusammengesetzte in einem Feld, und dann haben wir es auch einfacher mit der Selektion … da müssen
 * wir aus unserem Summary einen eigenen Datentyp, ein Feld machen, wo man dann bei dem Feld auswählen kann, was
 * es zusammenfasst. Und bei jeder Änderung muss es natürlich neu geschrieben werden.»*
 *
 * ⚠️ *Welche Felder es zusammenfasst, sagt `summary_fields` **an diesem Feld** — dieselbe Einstellung, die der
 * Wähler seit [D-753](../../../../docs/NewConcept/90-decision-log.md) liest, nur an der Stelle statt am Knoten.
 * Gerechnet wird mit denselben Worten wie dort (eine Stufe tief, Präfix und Einheit als Zeichen, D-797).*
 *
 * ⚠️ *Gespeichert, anders als der Weg ([D-751](../../../../docs/NewConcept/90-decision-log.md)) und der Sprung
 * (D-769), die beim Zeichnen gerechnet werden: gespeichert lässt sich danach filtern und sortieren — sein Grund.*
 *
 * @see docs/NewConcept/90-decision-log.md D-885
 */
final class SummaryType extends SpecialisedType
{
    public function type(): SimpleType
    {
        return SimpleType::Summary;
    }

    public function nodeName(): string
    {
        return 'Zusammenfassung';
    }

    public function column(): string
    {
        return 'value_text';
    }

    /** Eingegeben wird sie nie; was doch ankommt, wird beim nächsten Schreiben überschrieben. */
    public function valueFrom(string $characters): TypedValue
    {
        return TypedValue::ofText($characters);
    }
}
