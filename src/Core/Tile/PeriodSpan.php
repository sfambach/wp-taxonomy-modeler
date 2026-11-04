<?php declare(strict_types=1);

namespace Taxmod\Core\Tile;

/**
 * How long a period of the period view is (D-1167): one day, one month, one year.
 *
 * @see docs/pakete/kacheln/konzept.md §6
 */
enum PeriodSpan: string
{
    case Day   = 'day';
    case Month = 'month';
    case Year  = 'year';
}
