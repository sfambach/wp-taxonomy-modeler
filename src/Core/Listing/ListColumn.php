<?php declare(strict_types=1);

namespace Taxmod\Core\Listing;

/**
 * One column a list can show: fixed ones always stand, the others the user picks; `default` makes it part of the starting set.
 * A `number` column filters by number ({@see FilterTerm}); a column that is not `filterable` has no box in the filter row (D-1462).
 * A `secondary` column gives way first where the list is narrow (Live-Check 7, 21.11.2026: 706 px beside the navigation) — unless the
 * user chose the columns himself.
 *
 * @see docs/NewConcept/90-decision-log.md D-1054, D-1462
 */
final class ListColumn
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $default = true,
        public readonly bool $fixed = false,
        public readonly string $width = '',
        public readonly bool $number = false,
        public readonly bool $filterable = true,
        public readonly bool $secondary = false,
    ) {
    }
}
