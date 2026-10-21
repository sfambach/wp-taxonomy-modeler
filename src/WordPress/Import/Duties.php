<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Import\DutyTable;

/**
 * The duty table as an option (D-953, S4); until he saves one, the starting rows of `DutyTable::initial()` apply.
 *
 * @see docs/pakete/import/konzept.md §3.4
 */
final class Duties
{
    public const OPTION = 'taxmod_import_duties';

    public static function load(): DutyTable
    {
        $stored = get_option(self::OPTION, null);

        return is_array($stored) ? new DutyTable($stored) : DutyTable::initial();
    }

    public static function save(DutyTable $table): void
    {
        update_option(self::OPTION, $table->toArray(), false);
    }
}
