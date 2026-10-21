<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Import\DutyTable;

/**
 * The duty table lives in code, not in a setting (D-961): the starting rows of `DutyTable::initial()`, and a module that
 * takes over a role for a platform adds its row through {@see self::FILTER}.
 *
 * @see docs/pakete/import/konzept.md §3.4
 */
final class Duties
{
    public const FILTER = 'taxmod_import_duties';
    /** The option the «Import» tab wrote until 0.1.38; removed once by {@see InboxSchema::upgrade()}. */
    public const RETIRED_OPTION = 'taxmod_import_duties';

    public static function load(): DutyTable
    {
        $rows = apply_filters(self::FILTER, DutyTable::initial()->toArray());

        return new DutyTable(is_array($rows) ? $rows : []);
    }
}
