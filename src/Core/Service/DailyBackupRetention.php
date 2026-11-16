<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

/**
 * Which nightly copies go once a new one is made: all but the newest few (D-1407).
 *
 * The file names carry their time (`taeglich-YYYYmmdd-HHiiss-…`), so their order is their age.
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class DailyBackupRetention
{
    public const KEEP = 7;

    public const PREFIX = 'taeglich-';

    /**
     * @param list<string> $names File names in the backup folder, any kind.
     * @return list<string> The nightly copies beyond the newest `$keep`.
     */
    public static function toRemove(array $names, int $keep = self::KEEP): array
    {
        $nightly = array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, self::PREFIX) && str_ends_with($name, '.zip')));
        rsort($nightly, SORT_STRING);

        return array_slice($nightly, max(0, $keep));
    }

    /**
     * @param list<string> $names
     * @return string|null The newest nightly copy.
     */
    public static function newest(array $names): ?string
    {
        $nightly = array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, self::PREFIX) && str_ends_with($name, '.zip')));
        rsort($nightly, SORT_STRING);

        return $nightly[0] ?? null;
    }
}
