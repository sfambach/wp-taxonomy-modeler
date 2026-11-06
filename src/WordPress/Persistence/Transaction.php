<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

/**
 * One act written whole or not at all — the one place that opens, commits and rolls back a transaction (D-1186).
 *
 * ⚠️ *Under the guards' clamp a transaction is already open, and a second `START TRANSACTION` would commit it silently
 * (`scripts/dev/lib/no-write.php`). Hence the savepoint, and the own transaction only where none stands.* The ability
 * `taxmod/apply` and the record save of the model screen had each written this by hand.
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

        $own = ! defined('TAXMOD_NO_WRITE');

        if ($own) {
            $wpdb->query('START TRANSACTION');
        }

        $wpdb->query('SAVEPOINT ' . $savepoint);

        try {
            $outcome = $act();
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK TO SAVEPOINT ' . $savepoint);

            if ($own) {
                $wpdb->query('ROLLBACK');
            }

            throw $e;
        }

        $wpdb->query('RELEASE SAVEPOINT ' . $savepoint);

        if ($own) {
            $wpdb->query('COMMIT');
        }

        return $outcome;
    }
}
