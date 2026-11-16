<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

/**
 * Was ein Rechenschritt mit dem bisherigen Ergebnis und seinem Feld tut ([D-1022](../../../docs/NewConcept/90-decision-log.md)).
 *
 * *Die vier Grundrechenarten aus [D-130](../../../docs/NewConcept/90-decision-log.md) (`+ − × ÷`), sonst nichts.*
 */
enum CalculationOperation: string
{
    case Plus = 'plus';
    case Minus = 'minus';
    case Times = 'times';
    case DividedBy = 'divided_by';

    /** D-1393: the field only where nothing has given a value yet — «empty = the material's» (D-1335). */
    case Otherwise = 'otherwise';
}
