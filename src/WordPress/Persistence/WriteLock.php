<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Exception\ConcurrentChange;

/**
 * One writer at a time for a read–check–write that a version cannot guard — a booking that checks the stock it is about to take
 * ([D-1148](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *Two pickers who both read «1 left» and both book «−1» touch no common record: each line is a new one. The guard is therefore
 * the order, not a version: the second booking waits for the first, then reads the stock again and is refused if it is no longer
 * there. It lasts one request — milliseconds — and is not the editing lock D-089 rejects: nobody is kept from opening or filling
 * a form, and a closed laptop holds nothing, because MySQL drops the lock with the connection.*
 *
 * ```mermaid
 * flowchart LR
 *   A["booking A"] -->|GET_LOCK| R["read stock · check · write"] -->|RELEASE_LOCK| D["done"]
 *   B["booking B"] -.->|waits| R
 * ```
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class WriteLock
{
    /** How long a booking waits for the one before it; far above the time a booking takes. */
    public const WAIT_SECONDS = 10;

    /**
     * @template T
     * @param  string        $key  What is being serialised, e.g. `stock` — one name per kind of check, not per record.
     * @param  callable(): T $work The read, the check and the write.
     * @return T
     * @throws ConcurrentChange when the previous holder did not finish in time.
     */
    public static function around(string $key, callable $work, int $waitSeconds = self::WAIT_SECONDS): mixed
    {
        global $wpdb;

        // ⚠️ *SQLite (the cloud VM) knows no named locks and serialises every write itself.*
        if (defined('DB_ENGINE') && DB_ENGINE === 'sqlite') {
            return $work();
        }

        $name = self::name($key);
        $got  = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $waitSeconds));

        if ((string) $got !== '1') {
            throw ConcurrentChange::busy($key);
        }

        try {
            return $work();
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    /** The lock's name: per site, so two installations on one database server do not wait for each other; MySQL allows 64 characters. */
    public static function name(string $key): string
    {
        global $wpdb;

        return substr($wpdb->prefix . 'taxmod_' . preg_replace('/[^a-z0-9_]/', '', strtolower($key)), 0, 64);
    }
}
