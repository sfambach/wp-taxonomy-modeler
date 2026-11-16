<?php declare(strict_types=1);

namespace Taxmod\Core\Role;

use Taxmod\Core\Exception\RoleMissing;

/**
 * The one place a module learns which node, field or value plays a role — `board_revision`, `revision_layers` — so no id
 * stands in a module's code and no two modules hold the same fact (D-1394).
 *
 * ```mermaid
 * flowchart LR
 *   B[roles-live.json] -->|only where every shared id agrees| S[(stored roles)] --> R[RoleMap] --> M[modules]
 * ```
 *
 * @see docs/gestaltung-und-architektur.md R9
 */
final class RoleMap
{
    public const GROUPS = ['nodes', 'fields', 'values'];

    /** @param array<string, array<string, int>> $ids group → role name → id */
    private function __construct(private readonly array $ids)
    {
    }

    /** @param array<string, mixed> $raw {nodes: {name: id}, fields: {…}, values: {…}}; anything else is dropped */
    public static function fromArray(array $raw): self
    {
        $ids = [];

        foreach (self::GROUPS as $group) {
            $ids[$group] = [];

            foreach (is_array($raw[$group] ?? null) ? $raw[$group] : [] as $key => $id) {
                if (is_string($key) && preg_match('/^[a-z][a-z0-9_]*$/', $key) === 1 && is_numeric($id) && (int) $id > 0) {
                    $ids[$group][$key] = (int) $id;
                }
            }
        }

        return new self($ids);
    }

    /**
     * The stored roles, completed by the bundled ones where the stored ones are that site: every role both name has the
     * same id. A new release then brings the roles it added; another site, which differs somewhere, stays as it is.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $bundled
     */
    public static function completed(array $stored, array $bundled): self
    {
        $own    = self::fromArray($stored);
        $theirs = self::fromArray($bundled);
        $shared = 0;

        foreach (self::GROUPS as $group) {
            foreach ($own->ids[$group] as $key => $id) {
                if (isset($theirs->ids[$group][$key])) {
                    if ($theirs->ids[$group][$key] !== $id) {
                        return $own;
                    }

                    ++$shared;
                }
            }
        }

        if ($shared === 0) {
            return $own;
        }

        $ids = [];

        foreach (self::GROUPS as $group) {
            $ids[$group] = $own->ids[$group] + $theirs->ids[$group];
        }

        return new self($ids);
    }

    public function node(string $key): int
    {
        return $this->find('nodes', $key) ?? throw RoleMissing::named('nodes', $key);
    }

    public function field(string $key): int
    {
        return $this->find('fields', $key) ?? throw RoleMissing::named('fields', $key);
    }

    public function value(string $key): int
    {
        return $this->find('values', $key) ?? throw RoleMissing::named('values', $key);
    }

    public function find(string $group, string $key): ?int
    {
        return $this->ids[$group][$key] ?? null;
    }

    /**
     * The declared roles that are required and not set.
     *
     * @param list<array{module?: string, group?: string, key?: string, required?: bool}> $declared
     * @return list<array{module: string, group: string, key: string}>
     */
    public function missing(array $declared): array
    {
        $out = [];

        foreach ($declared as $role) {
            $group = (string) ($role['group'] ?? '');
            $key   = (string) ($role['key'] ?? '');

            if (($role['required'] ?? false) === true && in_array($group, self::GROUPS, true) && $this->find($group, $key) === null) {
                $out[] = ['module' => (string) ($role['module'] ?? ''), 'group' => $group, 'key' => $key];
            }
        }

        return $out;
    }

    public function isEmpty(): bool
    {
        return array_sum(array_map('count', $this->ids)) === 0;
    }

    /** @return array<string, array<string, int>> */
    public function toArray(): array
    {
        return $this->ids;
    }
}
