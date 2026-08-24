<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Exception\ConcurrentChange;
use Taxmod\Core\Exception\NodeNotFound;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Repository\NodeRepository;

/**
 * Nodes in a table of our own (AR-1), reached through `$wpdb`.
 *
 * ⚠️ **Every variable goes through `prepare()`** — `CD-6`, without exception, including the
 * `LIKE` patterns, whose `%` must be escaped with `esc_like()` first or a name containing a
 * percent sign silently widens the match.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class WpdbNodeRepository implements NodeRepository
{
    public function byId(int $id): Node
    {
        return $this->find($id) ?? throw NodeNotFound::withId($id);
    }

    public function find(int $id): ?Node
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT id, version, name, path FROM ' . Schema::table('nodes') . ' WHERE id = %d', $id),
            ARRAY_A
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function add(Node $node): void
    {
        global $wpdb;

        $wpdb->insert(
            Schema::table('nodes'),
            ['id' => $node->id, 'version' => $node->version, 'name' => $node->name, 'path' => $node->path],
            ['%d', '%d', '%s', '%s']
        );
    }

    public function save(Node $node, int $expectedVersion): void
    {
        global $wpdb;

        // The WHERE carries the expected version, so the guard is the write itself rather than
        // a read followed by a hopeful update (P4c).
        $written = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Schema::table('nodes') . ' SET version = %d, name = %s, path = %s WHERE id = %d AND version = %d',
                $node->version,
                $node->name,
                $node->path,
                $node->id,
                $expectedVersion
            )
        );

        if ($written === 1) {
            return;
        }

        $current = $this->find($node->id);

        if ($current === null) {
            throw NodeNotFound::withId($node->id);
        }

        // MySQL reports 0 changed rows for a write that matched but altered nothing. Only a
        // version that actually moved on is a collision.
        if ($current->version !== $expectedVersion) {
            throw ConcurrentChange::on($node->id, $expectedVersion, $current->version);
        }
    }

    public function childrenOf(Node $parent): array
    {
        global $wpdb;

        // Children are the rows whose path is the parent's plus exactly one more segment.
        // One statement, any depth — the tree is never walked level by level (`CD-7`).
        $pattern = $wpdb->esc_like($parent->path . '.') . '%';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, version, name, path FROM ' . Schema::table('nodes') . '
                 WHERE path LIKE %s AND path NOT LIKE %s
                 ORDER BY name ASC',
                $pattern,
                $pattern . '.%'
            ),
            ARRAY_A
        );

        return array_map($this->hydrate(...), $rows ?: []);
    }

    public function moveSubtree(string $oldPath, string $newPath): void
    {
        global $wpdb;

        // One statement for the whole subtree. Done node by node this would be N+1, which the
        // code standard forbids outright (`CD-7`).
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Schema::table('nodes') . '
                 SET path = CONCAT(%s, SUBSTRING(path, %d))
                 WHERE path LIKE %s',
                $newPath,
                strlen($oldPath) + 1,
                $wpdb->esc_like($oldPath . '.') . '%'
            )
        );
    }

    public function purgeSubtree(Node $node): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . Schema::table('nodes') . ' WHERE id = %d OR path LIKE %s',
                $node->id,
                $wpdb->esc_like($node->path . '.') . '%'
            )
        );
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Node
    {
        return Node::fromStorage(
            (int) $row['id'],
            (int) $row['version'],
            (string) $row['name'],
            (string) $row['path'],
        );
    }
}
