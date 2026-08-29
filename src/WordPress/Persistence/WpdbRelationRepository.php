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
                'hide'     => $relation->hide ? 1 : 0,
            ],
            ['%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d']
        );
    }

    public function save(Relation $relation, int $expectedVersion): void
    {
        global $wpdb;

        $parked    = $relation->parkedByGroup === null ? 'NULL' : '%d';
        $arguments = [
            $relation->version,
            $relation->fromId,
            $relation->toId,
            $relation->kind->value,
            $relation->name,
            $relation->position,
            // ⚠️ *Order matters and is not obvious: `hide = %d` sits **before**
            // `parked_by_group_id` in the SQL above, so its argument goes here and not after the
            // conditional one. Placeholders are positional; a swap would write the change group
            // into `hide` and nothing would complain.*
            $relation->hide ? 1 : 0,
        ];

        if ($relation->parkedByGroup !== null) {
            $arguments[] = $relation->parkedByGroup;
        }

        $arguments[] = $relation->id;
        $arguments[] = $expectedVersion;

        // The expected version rides in the WHERE, so the guard is the write itself rather
        // than a read followed by a hopeful update (P4c).
        $written = $wpdb->query(
            $wpdb->prepare(
                // ⚠️ **A literal `NULL`, not a placeholder.** `$wpdb->prepare()` turns a null into
                // an **empty string**, which a `bigint` column stores as **0** — and a zero change
                // group reads as *parked by an act that never happened*. Found by a boundary check:
                // restoring an attribute left it parked.
                'UPDATE ' . Schema::table('relations') . '
                 SET version = %d, from_id = %d, to_id = %d, kind = %s, name = %s, position = %d,
                     hide = %d,
                     parked_by_group_id = ' . $parked . '
                 WHERE id = %d AND version = %d',
                ...$arguments
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
                'SELECT id, version, from_id, to_id, kind, name, position, hide FROM ' . Schema::table('relations') . '
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
                'SELECT id, version, from_id, to_id, kind, name, position, hide FROM ' . Schema::table('relations') . '
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
                'SELECT id, version, from_id, to_id, kind, name, position, hide FROM ' . Schema::table('relations') . '
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



    public function nextFieldPositionUnder(int $ownerId): int
    {
        global $wpdb;

        $highest = $wpdb->get_var($wpdb->prepare(
            'SELECT MAX(position) FROM ' . Schema::table('relations') . ' WHERE from_id = %d AND kind <> %s',
            $ownerId,
            RelationKind::Inheritance->value
        ));

        return $highest === null ? 0 : (int) $highest + 1;
    }

    public function fieldEdgesOf(array $ownerIds): array
    {
        global $wpdb;

        if ($ownerIds === []) {
            return [];
        }

        // The id list is built from integers we cast ourselves, so it carries no user input —
        // but it is still assembled with placeholders rather than glued in (`CD-6`).
        $places = implode(',', array_fill(0, count($ownerIds), '%d'));

        // ⚠️ **Parked attributes are left out here**, because D-128 says a parked one is *hidden by
        // default in its owning node — a model full of ghost attributes is unreadable*. Whoever
        // wants to see them asks {@see parkedFieldEdgesOf()} instead, which is the *show
        // deleted* toggle rather than a second reading of the same query.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, version, from_id, to_id, kind, name, position, parked_by_group_id, hide
                 FROM ' . Schema::table('relations') . "
                 WHERE from_id IN ({$places}) AND kind <> %s AND parked_by_group_id IS NULL
                 ORDER BY position ASC, id ASC",
                [...array_map(intval(...), $ownerIds), RelationKind::Inheritance->value]
            ),
            ARRAY_A
        );

        return array_map($this->hydrate(...), $rows ?: []);
    }

    public function fieldEdgesTo(array $targetIds): array
    {
        global $wpdb;

        if ($targetIds === []) {
            return [];
        }

        $places = implode(',', array_fill(0, count($targetIds), '%d'));

        // ⚠️ **`to_id` and not `from_id` — that one word is the whole method** ([D-199]). *Ordered by
        // the owning node so the section reads as «who uses me», grouped, rather than as a pile of
        // edge ids.*
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, version, from_id, to_id, kind, name, position, parked_by_group_id, hide
                 FROM ' . Schema::table('relations') . "
                 WHERE to_id IN ({$places}) AND kind <> %s AND parked_by_group_id IS NULL
                 ORDER BY from_id ASC, position ASC, id ASC",
                [...array_map(intval(...), $targetIds), RelationKind::Inheritance->value]
            ),
            ARRAY_A
        );

        return array_map($this->hydrate(...), $rows ?: []);
    }

    /** @return list<Relation> The removed ones, for D-128's *show deleted*. */
    public function parkedFieldEdgesOf(array $ownerIds): array
    {
        global $wpdb;

        if ($ownerIds === []) {
            return [];
        }

        $places = implode(',', array_fill(0, count($ownerIds), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, version, from_id, to_id, kind, name, position, parked_by_group_id, hide
                 FROM ' . Schema::table('relations') . "
                 WHERE from_id IN ({$places}) AND kind <> %s AND parked_by_group_id IS NOT NULL
                 ORDER BY position ASC, id ASC",
                [...array_map(intval(...), $ownerIds), RelationKind::Inheritance->value]
            ),
            ARRAY_A
        );

        return array_map($this->hydrate(...), $rows ?: []);
    }

    /**
     * Every edge with one end on any of these nodes — both ends, every kind.
     *
     * ⚠️ *One statement for the whole set, because a purge over forty parked nodes must not be forty
     * queries (`CD-7`). Ids are cast here, so nothing user-written reaches the SQL, and placeholders
     * are used anyway (`CD-6`).*
     */
    public function edgesTouching(array $nodeIds): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_map(intval(...), $nodeIds)));

        if ($ids === []) {
            return [];
        }

        $places = implode(",", array_fill(0, count($ids), "%d"));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . Schema::table("relations") . "
                 WHERE from_id IN ({$places}) OR to_id IN ({$places})",
                ...[...$ids, ...$ids]
            ),
            ARRAY_A
        );

        return array_map($this->hydrate(...), $rows ?: []);
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
            isset($row['parked_by_group_id']) ? (int) $row['parked_by_group_id'] : null,
            (bool) ($row['hide'] ?? false),
        );
    }
}
