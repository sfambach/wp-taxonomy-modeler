<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Repository\IdentityAllocator;

/**
 * Hands out model ids from a single counter, atomically, without a lock.
 *
 * ⚠️ **Why this exists at all.** Nodes and relations share one identity space (C11) so that
 * `owner_id` can be a single column over both (D-090) — and two tables cannot share one
 * `AUTO_INCREMENT`. MySQL has no sequences, so the counter is ours.
 *
 * ⚠️ **The concept does not prescribe a mechanism** — the seven tables of D-083 contain no
 * identity table. This is a boundary choice, listed among the Package 1 assumptions, and it is
 * behind {@see IdentityAllocator} precisely so it can be swapped without the core noticing.
 *
 * **`LAST_INSERT_ID(expr)` is what makes it lock-free.** The single `UPDATE` both increments the
 * counter and remembers the new value *for this connection only*, so two simultaneous callers
 * cannot read the same number. A read-then-write pair would need a lock and would still be the
 * classic race.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class OptionIdentityAllocator implements IdentityAllocator
{
    public const OPTION = 'taxmod_model_last_id';

    public function next(): int
    {
        global $wpdb;

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(option_value + 1) WHERE option_name = %s",
                self::OPTION
            )
        );

        if ($updated !== 1) {
            // First call on a fresh installation, or somebody removed the row. Seeding it
            // through the options API keeps autoload and caching consistent.
            add_option(self::OPTION, '0', '', true);
            wp_cache_delete(self::OPTION, 'options');

            return $this->next();
        }

        wp_cache_delete(self::OPTION, 'options');

        return (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
    }
}
