<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

/**
 * The counts (and sums) on tiles, kept until the data under them change (D-1198): a tile asks for its counts under a scope, the one
 * data class that writes that scope's table forgets them on every write. Forgetting raises the scope's generation, so every
 * cached count of the scope is gone with one small option write; old entries run out on their own.
 *
 * ```mermaid
 * flowchart LR
 *   T["tile: remember(scope, source, count)"] --> K{"cached under\nscope generation?"}
 *   K -->|yes| R["counts"]
 *   K -->|no| C["count() · store"] --> R
 *   W["data class writes"] --> F["forget(scope): generation + 1"]
 * ```
 *
 * @see docs/gestaltung-und-architektur.md
 */
final class TileCounts
{
    /** Every count read from the model's records: forgotten by every write to them. */
    public const MODEL = 'model';

    /** The print jobs table. */
    public const PRINT_JOBS = 'print_jobs';

    private const GENERATION = 'taxmod_tile_generation';

    private const PREFIX = 'taxmod_tiles_';

    /** *A safety net only: a write outside the data classes is seen after this at the latest.* */
    private const LIFETIME = HOUR_IN_SECONDS;

    /**
     * Scopes forgotten in this request and not counted since: a bulk write of a thousand rows raises the generation once
     * now and once more when the request ends, so a count taken by another request in between does not outlive the writes.
     *
     * @var array<string, true>
     */
    private static array $forgotten = [];

    /**
     * @param callable(): array<string, int|float> $count
     * @return array<string, int|float>
     */
    public static function remember(string $scope, string $source, callable $count): array
    {
        $key    = self::key($scope, $source);
        $cached = get_transient($key);

        if (is_array($cached)) {
            return $cached;
        }

        $counts = array_map(static fn (int|float|string $v): int|float => is_string($v) ? +$v : $v, $count());
        unset(self::$forgotten[$scope]);
        set_transient($key, $counts, self::LIFETIME);

        return $counts;
    }

    public static function forget(string $scope): void
    {
        if (isset(self::$forgotten[$scope])) {
            return;
        }

        self::raise($scope);
        self::$forgotten[$scope] = true;
        add_action('shutdown', static fn () => self::raise($scope));
    }

    private static function raise(string $scope): void
    {
        $generations         = self::generations();
        $generations[$scope] = ($generations[$scope] ?? 0) + 1;
        update_option(self::GENERATION, $generations, true);
    }

    private static function key(string $scope, string $source): string
    {
        return self::PREFIX . md5($scope . '|' . (self::generations()[$scope] ?? 0) . '|' . $source);
    }

    /** @return array<string, int> */
    private static function generations(): array
    {
        $stored = get_option(self::GENERATION, []);

        return is_array($stored) ? array_map('intval', $stored) : [];
    }
}
