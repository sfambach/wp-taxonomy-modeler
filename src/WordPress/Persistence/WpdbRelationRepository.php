<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Exception\ConcurrentChange;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Repository\RelationRepository;

/**
 * Edges in a table of our own, reached through `$wpdb`.
 *
 * ⚠️ **The inheritance rows are the tree.** `nodes.path` is derived from them (D-014); this is
 * where the truth is written, and the path is rewritten from it afterwards.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class WpdbRelationRepository implements RelationRepository
{
    public function add(Relation $relation): void
    {
        global $wpdb;

        $wpdb->insert(
            Schema::table('relations'),
            [
                'id'       => $relation->id,
                'version'  => $relation->version,
                'from_id'  => $relation->fromId,
                'to_id'    => $relation->toId,
                'kind'     => $relation->kind->value,
                'name'     => $relation->name,
                'position' => $relation->position,
            ],
            ['%d', '%d', '%d', '%d', '%s', '%s', '%d']
        );
    }

    public function save(Relation $relation, int $expectedVersion): void
    {
        global $wpdb;

        // The expected version rides in the WHERE, so the guard is the write itself rather
        // than a read followed by a hopeful update (P4c).
        $written = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Schema::table('relations') . '
                 SET version = %d, from_id = %d, to_id = %d, kind = %s, name = %s, position = %d
                 WHERE id = %d AND version = %d',
                $relation->version,
                $relation->fromId,
                $relation->toId,
                $relation->kind->value,
                $relation->name,
                $relation->position,
                $relation->id,
                $expectedVersion
            )
        );

        if ($written === 1) {
            return;
        }

        // MySQL reports 0 changed rows for a write that matched but altered nothing, so only
        // a version that actually moved on is a collision.
        $current = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT version FROM ' . Schema::table('relations') . ' WHERE id = %d',
            $relation->id
        ));

        if ($current !== 0 && $current !== $expectedVersion) {
            throw ConcurrentChange::on($relation->id, $expectedVersion, $current);
        }
    }

    public function inheritanceEdgeTo(int $childId): ?Relation
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, version, from_id, to_id, kind, name, position FROM ' . Schema::table('relations') . '
                 WHERE to_id = %d AND kind = %s',
                $childId,
                RelationKind::Inheritance->value
            ),
            ARRAY_A
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function childEdgesOf(int $parentId): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, version, from_id, to_id, kind, name, position FROM ' . Schema::table('relations') . '
                 WHERE from_id = %d AND kind = %s
                 ORDER BY position ASC, id ASC',
                $parentId,
                RelationKind::Inheritance->value
            ),
            ARRAY_A
        );

        return array_map($this->hydrate(...), $rows ?: []);
    }

    public function nextPositionUnder(int $parentId): int
    {
        global $wpdb;

        $highest = $wpdb->get_var($wpdb->prepare(
            'SELECT MAX(position) FROM ' . Schema::table('relations') . ' WHERE from_id = %d AND kind = %s',
            $parentId,
            RelationKind::Inheritance->value
        ));

        return $highest === null ? 0 : (int) $highest + 1;
    }

    public function allInheritanceEdges(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, version, from_id, to_id, kind, name, position FROM ' . Schema::table('relations') . '
                 WHERE kind = %s
                 ORDER BY from_id ASC, position ASC, id ASC',
                RelationKind::Inheritance->value
            ),
            ARRAY_A
        );

        return array_map($this->hydrate(...), $rows ?: []);
    }


    public function reparentChildEdges(int $fromParentId, int $toParentId, int $startPosition): void
    {
        global $wpdb;

        // One statement, however many children there are. `position + start` keeps their
        // order relative to each other while placing them after their new siblings.
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::table('relations') . '
             SET from_id = %d, position = position + %d, version = version + 1
             WHERE from_id = %d AND kind = %s',
            $toParentId,
            $startPosition,
            $fromParentId,
            RelationKind::Inheritance->value
        ));
    }

    public function purgeEdgesTouching(int $nodeId): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('relations') . ' WHERE from_id = %d OR to_id = %d',
            $nodeId,
            $nodeId
        ));
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Relation
    {
        return Relation::fromStorage(
            (int) $row['id'],
            (int) $row['version'],
            (int) $row['from_id'],
            (int) $row['to_id'],
            (string) $row['kind'],
            (string) $row['name'],
            (int) $row['position'],
        );
    }
}
