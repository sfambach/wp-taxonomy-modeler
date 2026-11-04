<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

/** What a key figure counts per period: the records, or the sum of one of their fields (D-1149). */
enum MetricMeasure: string
{
    case Count = 'count';
    case Sum = 'sum';
}
