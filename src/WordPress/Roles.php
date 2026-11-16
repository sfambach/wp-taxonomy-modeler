<?php declare(strict_types=1);

namespace Taxmod\WordPress;

use Taxmod\Core\Role\RoleMap;

/**
 * Where the roles of this site are kept (D-1394): one option, completed from the bundled `roles-live.json` where this site
 * is that one. Modules reach it only through {@see ModelDoor::roles()}.
 *
 * @see docs/gestaltung-und-architektur.md R9
 */
final class Roles
{
    public const OPTION = 'taxmod_roles';

    /** `apply_filters(FILTER, [])`: the roles modules use, `[['module' => …, 'group' => 'fields', 'key' => …, 'required' => bool], …]`. */
    public const FILTER = 'taxmod_roles';

    private static ?RoleMap $current = null;

    public static function current(): RoleMap
    {
        return self::$current ??= RoleMap::completed(self::stored(), self::bundled());
    }

    /**
     * Take a module's own role table into the shared one, once per module release: a role not set yet is added, a role set
     * to another id is left and returned as a conflict, never overwritten.
     *
     * @param array<string, mixed> $roles {nodes: {name: id}, fields: {…}, values: {…}}
     * @return list<array{group: string, key: string, stored: int, offered: int}>
     */
    public static function adopt(array $roles): array
    {
        $stored    = RoleMap::fromArray(self::stored())->toArray();
        $offered   = RoleMap::fromArray($roles)->toArray();
        $conflicts = [];
        $changed   = false;

        foreach ($offered as $group => $ids) {
            foreach ($ids as $key => $id) {
                if (! isset($stored[$group][$key])) {
                    $stored[$group][$key] = $id;
                    $changed              = true;
                } elseif ($stored[$group][$key] !== $id) {
                    $conflicts[] = ['group' => $group, 'key' => $key, 'stored' => $stored[$group][$key], 'offered' => $id];
                }
            }
        }

        if ($changed) {
            update_option(self::OPTION, $stored, true);
            self::$current = null;
        }

        return $conflicts;
    }

    /** @return list<array{module: string, group: string, key: string}> */
    public static function missing(): array
    {
        $declared = apply_filters(self::FILTER, []);

        return self::current()->missing(is_array($declared) ? array_values(array_filter($declared, 'is_array')) : []);
    }

    /** @return array<string, mixed> */
    private static function stored(): array
    {
        $stored = get_option(self::OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    /** @return array<string, mixed> */
    private static function bundled(): array
    {
        $file    = dirname(__DIR__, 2) . '/roles-live.json';
        $decoded = is_readable($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
