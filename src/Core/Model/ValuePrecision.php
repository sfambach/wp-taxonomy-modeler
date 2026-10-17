<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * Wie genau ein gespeichertes Datum bekannt ist, wenn nicht ganz — nur das Jahr, oder Monat und Jahr ([D-932](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *Kein Fall für «ganz»: ein Wert ohne Angabe ist ganz bekannt, wie jedes Datum vor D-932.*
 *
 * @see docs/NewConcept/90-decision-log.md
 */
enum ValuePrecision: string
{
    case Year  = 'year';
    case Month = 'month';
}
