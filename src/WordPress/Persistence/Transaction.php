<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

/**
 * One act written whole or not at all — the one place that opens, commits and rolls back a transaction (D-1186).
 *
 * ⚠️ *Under the guards' clamp a transaction is already open, and a second `START TRANSACTION` would commit it silently
 * (`scripts/dev/lib/no-write.php`). Hence the savepoint, and the own transaction only where none stands.* The ability
 * `taxmod/apply` and the record save of the model screen had each written this by hand. Since D-1419 it nests: a module's
 * {@see \Taxmod\WordPress\ModelDoor::inAct()} holds several `write-data` calls, each with its own inner savepoint.
 *
 * ```mermaid
 * flowchart LR
 *   S["START (own only)"] --> P[SAVEPOINT] --> A[act]
 *   A -->|ok| R["RELEASE · COMMIT"]
 *   A -->|throws| B["ROLLBACK TO · ROLLBACK"]
 * ```
 *
 * @see docs/gestaltung-und-architektur.md R1
 */
final class Transaction
{
    /** How many are open in this request: only the outermost starts and commits; an inner one is a savepoint of its own (D-1419). */
    private static int $depth = 0;

    /**
     * @template T
     * @param callable(): T $act
     * @return T
     */
    public static function whole(string $savepoint, callable $act): mixed
    {
        global $wpdb;

        // A savepoint is a name, not a value — it cannot be prepared, so it is held to a word.
        if (preg_match('/^[a-z_]+$/', $savepoint) !== 1) {
            throw new \InvalidArgumentException('A savepoint is a lower-case word: ' . $savepoint);
        }

        $own       = self::$depth === 0 && ! defined('TAXMOD_NO_WRITE');
        // ⚠️ *A nested act under the same name would replace the outer savepoint, and the outer rollback would then miss it.*
        $savepoint = $savepoint . '_' . self::$depth;

        if ($own) {
            $wpdb->query('START TRANSACTION');
        }

        $wpdb->query('SAVEPOINT ' . $savepoint);

        ++self::$depth;

        try {
            $outcome = $act();
        } catch (\Throwable $e) {
            --self::$depth;
            $wpdb->query('ROLLBACK TO SAVEPOINT ' . $savepoint);

            if ($own) {
                $wpdb->query('ROLLBACK');
            }

            throw $e;
        }

        --self::$depth;
        $wpdb->query('RELEASE SAVEPOINT ' . $savepoint);

        if ($own) {
            $wpdb->query('COMMIT');
        }

        return $outcome;
    }
}
