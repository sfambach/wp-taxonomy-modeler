<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Import\ImportSource;

/**
 * The sources the active modules registered through `taxmod_import_sources` (D-952). Anything that is not an
 * `ImportSource`, or repeats a key, is left out.
 *
 * @see docs/pakete/import/konzept.md §2
 */
final class Sources
{
    public const FILTER = 'taxmod_import_sources';

    /** @return array<string, ImportSource> key => source */
    public static function all(): array
    {
        $sources = [];

        foreach ((array) apply_filters(self::FILTER, []) as $source) {
            if ($source instanceof ImportSource && $source->key() !== '' && ! isset($sources[$source->key()])) {
                $sources[$source->key()] = $source;
            }
        }

        return $sources;
    }

    public static function find(string $key): ?ImportSource
    {
        return self::all()[$key] ?? null;
    }
}
