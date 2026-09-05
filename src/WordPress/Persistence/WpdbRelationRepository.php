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
    /**
     * ⚠️ **Die Id kommt aus dem `AUTO_INCREMENT` dieser Tabelle** (TASK-004) — dieselbe Zusage wie
     * bei {@see WpdbNodeRepository::add()}. *Id `0` heisst «vergib eine», jede andere bleibt.*
     */
    public function add(Relation $relation): Relation
    {
        global $wpdb;

        if ($relation->id === 0) {
            $wpdb->insert(
                Schema::table('relations'),
                [
                    'version'  => $relation->version,
                    'from_id'  => $relation->fromId,
                    'to_id'    => $relation->toId,
                    'kind'     => $relation->kind->value,
                    'name'     => $relation->name,
                    'sort_order' => $relation->sortOrder,
                    'hide'     => $relation->hide ? 1 : 0,
                    'multiplicity' => $relation->multiplicity->value,
                ],
                ['%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s']
            );

            return $relation->withAssignedId((int) $wpdb->insert_id);
        }

        $wpdb->insert(
            Schema::table('relations'),
            [
                'id'       => $relation->id,
                'version'  => $relation->version,
                'from_id'  => $relation->fromId,
                'to_id'    => $relation->toId,
                'kind'     => $relation->kind->value,
                'name'     => $relation->name,
                'sort_order' => $relation->sortOrder,
                'hide'     => $relation->hide ? 1 : 0,
                'multiplicity' => $relation->multiplicity->value,
            ],
            ['%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s']
        );

        return $relation;
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
            $relation->sortOrder,
            // ⚠️ *Order matters and is not obvious: `hide = %d` sits **before**
            // `parked_by_group_id` in the SQL above, so its argument goes here and not after the
            // conditional one. Placeholders are positional; a swap would write the change group
            // into `hide` and nothing would complain.*
            $relation->hide ? 1 : 0,
            // ⚠️ *Aus demselben Grund direkt hinter `hide`: `multiplicity = %s` steht dort im SQL
            // ([D-528](../../../docs/NewConcept/90-decision-log.md)).*
            $relation->multiplicity->value,
        ];

        if ($relation->parkedByGroup !== null) {
            $arguments[] = $relation->parkedByGroup;
        }

        $arguments[] = $relation->id;
        $arguments[] = $expectedVersion;

        // The expected version rides in the WHERE, so the guard is the write itself rather
        // than a read followed by a hopeful update (P4c).
        // ⚠️ *Vor dem Schreiben in den Schatten ([D-536](../../../docs/NewConcept/90-decision-log.md)).*
        Shadow::keepOne('relations', $relation->id);

        $written = $wpdb->query(
            $wpdb->prepare(
                // ⚠️ **A literal `NULL`, not a placeholder.** `$wpdb->prepare()` turns a null into
                // an **empty string**, which a `bigint` column stores as **0** — and a zero change
                // group reads as *parked by an act that never happened*. Found by a boundary check:
                // restoring an attribute left it parked.
                'UPDATE ' . Schema::table('relations') . '
                 SET version = %d, from_id = %d, to_id = %d, kind = %s, name = %s, sort_order = %d,
                     hide = %d,
                     multiplicity = %s,
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

    public function byId(int $edgeId): ?Relation
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, version, from_id, to_id, kind, name, sort_order, hide, multiplicity FROM ' . Schema::table('relations') . '
                 WHERE id = %d',
                $edgeId
            ),
            ARRAY_A
        );

        return $row === null ? null : $this->hydrate($row);
    }

    public function inheritanceEdgeTo(int $childId): ?Relation
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, version, from_id, to_id, kind, name, sort_order, hide, multiplicity FROM ' . Schema::table('relations') . '
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
                'SELECT id, version, from_id, to_id, kind, name, sort_order, hide, multiplicity FROM ' . Schema::table('relations') . '
                 WHERE from_id = %d AND kind = %s
                 ORDER BY sort_order ASC, id ASC',
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
            'SELECT MAX(sort_order) FROM ' . Schema::table('relations') . ' WHERE from_id = %d AND kind = %s',
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
                'SELECT id, version, from_id, to_id, kind, name, sort_order, hide, multiplicity FROM ' . Schema::table('relations') . '
                 WHERE kind = %s
                 ORDER BY from_id ASC, sort_order ASC, id ASC',
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
             SET from_id = %d, sort_order = sort_order + %d, version = version + 1
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
            'SELECT MAX(sort_order) FROM ' . Schema::table('relations') . ' WHERE from_id = %d AND kind <> %s',
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
                'SELECT id, version, from_id, to_id, kind, name, sort_order, parked_by_group_id, hide, multiplicity
                 FROM ' . Schema::table('relations') . "
                 WHERE from_id IN ({$places}) AND kind <> %s AND parked_by_group_id IS NULL
                 ORDER BY sort_order ASC, id ASC",
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
                'SELECT id, version, from_id, to_id, kind, name, sort_order, parked_by_group_id, hide, multiplicity
                 FROM ' . Schema::table('relations') . "
                 WHERE to_id IN ({$places}) AND kind <> %s AND parked_by_group_id IS NULL
                 ORDER BY from_id ASC, sort_order ASC, id ASC",
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
                'SELECT id, version, from_id, to_id, kind, name, sort_order, parked_by_group_id, hide, multiplicity
                 FROM ' . Schema::table('relations') . "
                 WHERE from_id IN ({$places}) AND kind <> %s AND parked_by_group_id IS NOT NULL
                 ORDER BY sort_order ASC, id ASC",
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

        // ⚠️ *Erst in den Schatten ([D-536](../../../docs/NewConcept/90-decision-log.md)): eine Kante,
        // die verschwindet, nimmt sonst mit, **warum** sie da war.*
        Shadow::keep('relations', 'from_id = %d OR to_id = %d', [$nodeId, $nodeId], true);

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('relations') . ' WHERE from_id = %d OR to_id = %d',
            $nodeId,
            $nodeId
        ));
    }

    public function settingsRecordIdsOfEdges(array $edgeIds): array
    {
        global $wpdb;

        $edgeIds = array_values(array_unique(array_filter(array_map(intval(...), $edgeIds))));

        if ($edgeIds === []) {
            return [];
        }

        $slots = implode(',', array_fill(0, count($edgeIds), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, settings_record_id, target_settings_record_id FROM ' . Schema::table('relations')
                    . " WHERE id IN ($slots)",
                ...$edgeIds
            ),
            ARRAY_A
        );

        $aus = [];

        foreach ($rows as $row) {
            $aus[(int) $row['id']] = [
                'own'    => (int) ($row['settings_record_id'] ?? 0),
                'target' => (int) ($row['target_settings_record_id'] ?? 0),
            ];
        }

        return $aus;
    }

    public function rememberSettingsRecord(int $edgeId, int $recordId): void
    {
        global $wpdb;

        // ⚠️ *Wie am Knoten: `NULL` heisst «hier nichts gesagt», `0` wäre eine Satz-Id, die es nie
        // gibt. `prepare()` setzt kein `NULL` ein, darum zwei Anweisungen.*
        if ($recordId === 0) {
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . Schema::table('relations') . ' SET settings_record_id = NULL WHERE id = %d',
                $edgeId
            ));

            return;
        }

        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::table('relations') . ' SET settings_record_id = %d WHERE id = %d',
            $recordId,
            $edgeId
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
            (int) $row['sort_order'],
            isset($row['parked_by_group_id']) ? (int) $row['parked_by_group_id'] : null,
            (bool) ($row['hide'] ?? false),
            (string) ($row['multiplicity'] ?? '1..1'),
        );
    }
}
