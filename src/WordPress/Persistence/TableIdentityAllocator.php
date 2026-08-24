<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Repository\IdentityAllocator;

/**
 * Hands out model ids from the `identities` table's own `AUTO_INCREMENT` (D-339).
 *
 * ⚠️ **The allocation state is the data.** That is the whole reason this replaced a counter in
 * `wp_options`: the counter lived in a different table from the ids it guarded, so any operation
 * that treated the two separately — a partial restore above all — could put it behind the data
 * and make it hand out numbers a second time. Since `owner_id` is one column over nodes *and*
 * edges (D-090), a reissued number attaches a setting to the wrong object with no error
 * anywhere. `AUTO_INCREMENT` cannot desync from its own table.
 *
 * ⚠️ **Nothing is ever deleted from `identities`.** A purged object's number stays spent, which
 * is what stops the changelog's frozen history (D-065) from later attaching to a new object.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class TableIdentityAllocator implements IdentityAllocator
{
    public function next(): int
    {
        global $wpdb;

        $table = Schema::table('identities');

        // The table has one column and it is the key, so there is nothing to insert but a row.
        $wpdb->query("INSERT INTO {$table} () VALUES ()");

        return (int) $wpdb->insert_id;
    }
}
