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
                    'from_node_id'  => $relation->fromNodeId,
                    'to_node_id'    => $relation->toNodeId,
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
                'from_node_id'  => $relation->fromNodeId,
                'to_node_id'    => $relation->toNodeId,
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

        $arguments = [
            $relation->version,
            $relation->fromNodeId,
            $relation->toNodeId,
            $relation->kind->value,
            $relation->name,
            $relation->sortOrder,
            $relation->hide ? 1 : 0,
            // ⚠️ *Aus demselben Grund direkt hinter `hide`: `multiplicity = %s` steht dort im SQL
            // ([D-528](../../../docs/NewConcept/90-decision-log.md)).*
            $relation->multiplicity->value,
        ];

        $arguments[] = $relation->id;
        $arguments[] = $expectedVersion;

        // The expected version rides in the WHERE, so the guard is the write itself rather
        // than a read followed by a hopeful update (P4c).
        // ⚠️ *Vor dem Schreiben in den Schatten ([D-536](../../../docs/NewConcept/90-decision-log.md)).*
        Shadow::keepOne('relations', $relation->id);

        $written = $wpdb->query(
            $wpdb->prepare(
                // ⚠️ *Das Parken steht hier nicht mehr* ([D-619](../../../docs/NewConcept/90-decision-log.md),
                // TASK-013): **eine geparkte Kante hat gar keine lebende Zeile** — sie steht im
                // Schatten. Siehe {@see self::park()}.
                'UPDATE ' . Schema::table('relations') . '
                 SET version = %d, from_node_id = %d, to_node_id = %d, kind = %s, name = %s, sort_order = %d,
                     hide = %d,
                     multiplicity = %s
                 WHERE id = %d AND version = %d',
                ...$arguments
            )
        );

        if ($written === 1) {
            return;
        }

        // MySQL reports 0 changed rows for a write that matched but altered nothing, so only
        // a version that actually moved on is a collision.
        $current = (int) Query::value('Version der Kante lesen', $wpdb->prepare(
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

        $row = Query::row('Kante lesen', $wpdb->prepare(
            'SELECT id, version, from_node_id, to_node_id, kind, name, sort_order, hide, multiplicity FROM ' . Schema::table('relations') . '
             WHERE id = %d',
            $edgeId
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    public function inheritanceEdgeTo(int $childId): ?Relation
    {
        global $wpdb;

        $row = Query::row('Vererbungskante zum Kind lesen', $wpdb->prepare(
            'SELECT id, version, from_node_id, to_node_id, kind, name, sort_order, hide, multiplicity FROM ' . Schema::table('relations') . '
             WHERE to_node_id = %d AND kind = %s',
            $childId,
            RelationKind::Inheritance->value
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    public function childEdgesOf(int $parentId): array
    {
        global $wpdb;

        $rows = Query::rows('Kinderkanten lesen', $wpdb->prepare(
            'SELECT id, version, from_node_id, to_node_id, kind, name, sort_order, hide, multiplicity FROM ' . Schema::table('relations') . '
             WHERE from_node_id = %d AND kind = %s
             ORDER BY sort_order ASC, id ASC',
            $parentId,
            RelationKind::Inheritance->value
        ));

        return array_map($this->hydrate(...), $rows ?: []);
    }

    public function nextPositionUnder(int $parentId): int
    {
        global $wpdb;

        $highest = Query::value('naechste Stelle unter dem Knoten lesen', $wpdb->prepare(
            'SELECT MAX(sort_order) FROM ' . Schema::table('relations') . ' WHERE from_node_id = %d AND kind = %s',
            $parentId,
            RelationKind::Inheritance->value
        ));

        return $highest === null ? 0 : (int) $highest + 1;
    }

    public function allInheritanceEdges(): array
    {
        global $wpdb;

        $rows = Query::rows('alle Vererbungskanten lesen', $wpdb->prepare(
            'SELECT id, version, from_node_id, to_node_id, kind, name, sort_order, hide, multiplicity FROM ' . Schema::table('relations') . '
             WHERE kind = %s
             ORDER BY from_node_id ASC, sort_order ASC, id ASC',
            RelationKind::Inheritance->value
        ));

        return array_map($this->hydrate(...), $rows ?: []);
    }


    public function reparentChildEdges(int $fromParentId, int $toParentId, int $startPosition): void
    {
        global $wpdb;

        // One statement, however many children there are. `position + start` keeps their
        // order relative to each other while placing them after their new siblings.
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::table('relations') . '
             SET from_node_id = %d, sort_order = sort_order + %d, version = version + 1
             WHERE from_node_id = %d AND kind = %s',
            $toParentId,
            $startPosition,
            $fromParentId,
            RelationKind::Inheritance->value
        ));
    }



    public function nextFieldPositionUnder(int $ownerId): int
    {
        global $wpdb;

        $highest = Query::value('naechste Stelle der Felder lesen', $wpdb->prepare(
            'SELECT MAX(sort_order) FROM ' . Schema::table('relations') . ' WHERE from_node_id = %d AND kind <> %s',
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
        //
        // ⚠️ *Seit [D-619](../../../docs/NewConcept/90-decision-log.md) braucht das keine Bedingung
        // mehr: **eine geparkte Kante steht im Schatten und nicht hier**. Die Auslassung ist die
        // Tabelle selbst.*
        $rows = Query::rows('Feldkanten des Knotens lesen', $wpdb->prepare(
            'SELECT id, version, from_node_id, to_node_id, kind, name, sort_order, hide, multiplicity
             FROM ' . Schema::table('relations') . "
             WHERE from_node_id IN ({$places}) AND kind <> %s
             ORDER BY sort_order ASC, id ASC",
            [...array_map(intval(...), $ownerIds), RelationKind::Inheritance->value]
        ));

        return array_map($this->hydrate(...), $rows ?: []);
    }

    public function fieldEdgesTo(array $targetIds): array
    {
        global $wpdb;

        if ($targetIds === []) {
            return [];
        }

        $places = implode(',', array_fill(0, count($targetIds), '%d'));

        // ⚠️ **`to_node_id` and not `from_node_id` — that one word is the whole method** ([D-199]). *Ordered by
        // the owning node so the section reads as «who uses me», grouped, rather than as a pile of
        // edge ids.*
        $rows = Query::rows('Feldkanten auf das Ziel lesen', $wpdb->prepare(
            'SELECT id, version, from_node_id, to_node_id, kind, name, sort_order, hide, multiplicity
             FROM ' . Schema::table('relations') . "
             WHERE to_node_id IN ({$places}) AND kind <> %s
             ORDER BY from_node_id ASC, sort_order ASC, id ASC",
            [...array_map(intval(...), $targetIds), RelationKind::Inheritance->value]
        ));

        return array_map($this->hydrate(...), $rows ?: []);
    }

    /**
     * @return list<Relation> The removed ones, for D-128's *show deleted*.
     *
     * ⚠️ **Aus dem Schatten gelesen, seit [D-619](../../../docs/NewConcept/90-decision-log.md).**
     * *Geparkt heisst jetzt: die Zeile steht in `relations_history` mit einer Änderungsgruppe und
     * hat lebend keine Entsprechung mehr. **Beide Hälften werden gebraucht** — ohne die zweite käme
     * eine zurückgeholte Kante doppelt zurück, einmal lebend und einmal als Geist.*
     */
    public function parkedFieldEdgesOf(array $ownerIds): array
    {
        global $wpdb;

        if ($ownerIds === []) {
            return [];
        }

        $places   = implode(',', array_fill(0, count($ownerIds), '%d'));
        $schatten = Schema::table('relations_history');
        $lebend   = Schema::table('relations');

        // ⚠️ *Der jüngste Schattenstand je Id — beim Parken wächst die Version nicht, aber eine Kante,
        // die schon einmal geparkt und zurückgeholt wurde, hat mehrere.*
        $rows = Query::rows('geparkte Feldkanten aus dem Schatten lesen', $wpdb->prepare(
            "SELECT h.id, h.version, h.from_node_id, h.to_node_id, h.kind, h.name, h.sort_order,
                    h.parked_by_group_id, h.hide, h.multiplicity
             FROM {$schatten} h
             INNER JOIN (
                 SELECT id, MAX(version) AS version FROM {$schatten}
                 WHERE from_node_id IN ({$places}) AND kind <> %s AND parked_by_group_id IS NOT NULL
                 GROUP BY id
             ) neuste ON neuste.id = h.id AND neuste.version = h.version
             WHERE h.parked_by_group_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM {$lebend} l WHERE l.id = h.id)
             ORDER BY h.sort_order ASC, h.id ASC",
            [...array_map(intval(...), $ownerIds), RelationKind::Inheritance->value]
        ));

        return array_map($this->hydrate(...), $rows ?: []);
    }

    /**
     * Eine Kante parken — **sie und ihre Wertzeilen wandern in den Schatten**.
     *
     * ⚠️ **[D-619](../../../docs/NewConcept/90-decision-log.md), sein Wort auf drei vorgelegte Wege:**
     * *«1»* — mitwandern. *«Stehenbleiben» hiesse Wertzeilen ohne ihre Kante, und das ist derselbe
     * Schaden, den `id-space-check` seit Wochen als «Datensatz ohne Knoten» meldet.*
     *
     * ```mermaid
     * flowchart LR
     *   K["relations · die Kante"] --> H[("relations_history · mit Gruppe")]
     *   W["record_values · ihre Werte"] --> V[("record_values_history")]
     *   H --> Z["lebend gelöscht — eine Gruppe, ein Akt"]
     * ```
     *
     * ⚠️ **Parken ist kein Löschen** ([D-604](../../../docs/NewConcept/90-decision-log.md)): der
     * Schatten hält alles, und {@see self::unpark()} ist die Umkehrung und keine zweite Mechanik.
     */
    public function park(int $edgeId, int $changeGroupId): void
    {
        global $wpdb;

        // 1 · Die Kante in den Schatten, dort mit der Gruppe gestempelt.
        Shadow::keepOne('relations', $edgeId, true);

        Query::run('Parkgruppe im Schatten vermerken', $wpdb->prepare(
            'UPDATE ' . Schema::table('relations_history') . '
             SET parked_by_group_id = %d
             WHERE id = %d AND version = (SELECT * FROM (SELECT MAX(version) FROM '
                . Schema::table('relations_history') . ' WHERE id = %d) AS neuste)',
            $changeGroupId,
            $edgeId,
            $edgeId
        ));

        // 2 · Die Wertzeilen der Kante gehen denselben Weg — **eine Gruppe, ein Akt**.
        Shadow::keep('record_values', 'edge_id = %d', [$edgeId], true);

        Query::run('Wertzeilen der geparkten Kante entfernen', $wpdb->prepare(
            'DELETE FROM ' . Schema::table('record_values') . ' WHERE edge_id = %d',
            $edgeId
        ));

        // 3 · Und erst jetzt die lebende Zeile.
        Query::run('geparkte Kante lebend entfernen', $wpdb->prepare(
            'DELETE FROM ' . Schema::table('relations') . ' WHERE id = %d',
            $edgeId
        ));
    }

    /**
     * Eine geparkte Kante zurückholen — **mit ihren Wertzeilen**, in umgekehrter Reihenfolge.
     *
     * ⚠️ *Vorwärts geschrieben und nicht zurückgespult ([D-172](../../../docs/NewConcept/90-decision-log.md)):
     * die Version zählt weiter, der Schatten wird nicht kürzer.*
     *
     * @return Relation|null `null`, wenn dort nichts geparkt liegt.
     */
    public function unpark(int $edgeId): ?Relation
    {
        global $wpdb;

        $schatten = Schema::table('relations_history');

        $zeile = Query::row('geparkte Kante im Schatten suchen', $wpdb->prepare(
            "SELECT * FROM {$schatten}
             WHERE id = %d AND parked_by_group_id IS NOT NULL
             ORDER BY version DESC LIMIT 1",
            $edgeId
        ));

        if ($zeile === null) {
            return null;
        }

        $lebt = Query::value('lebt die Kante schon wieder', $wpdb->prepare(
            'SELECT id FROM ' . Schema::table('relations') . ' WHERE id = %d',
            $edgeId
        ));

        if ($lebt !== null) {
            return $this->byId($edgeId);
        }

        $spalten = [];

        foreach ($zeile as $name => $wert) {
            // ⚠️ *`parked_by_group_id` bleibt im Schatten stehen — dort ist sie die Aussage «hier
            // wurde geparkt». Lebend gibt es die Spalte seit TASK-013 nicht mehr, und
            // {@see Schema::SHADOW_ONLY_IN} ist die eine Stelle, die das sagt.*
            if (in_array($name, [...Schema::SHADOW_ONLY, ...(Schema::SHADOW_ONLY_IN['relations_history'] ?? [])], true)) {
                continue;
            }

            $spalten[$name] = $wert;
        }

        $spalten['version'] = (int) $zeile['version'] + 1;

        $wpdb->insert(Schema::table('relations'), $spalten, array_fill(0, count($spalten), '%s'));

        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Die geparkte Kante liess sich nicht zurückholen: ' . $wpdb->last_error);
        }

        $this->unparkValues($edgeId);

        return $this->byId($edgeId);
    }

    /**
     * Die Wertzeilen einer zurückgeholten Kante wieder lebend hinstellen.
     *
     * ⚠️ *Der jüngste Schattenstand je Wertzeile, und nur der als gelöscht markierte — eine Zeile,
     * die es lebend noch gibt, wird nicht ein zweites Mal eingefügt.*
     */
    private function unparkValues(int $edgeId): void
    {
        global $wpdb;

        $schatten = Schema::table('record_values_history');
        $lebend   = Schema::table('record_values');

        $zeilen = Query::rows('Wertzeilen der geparkten Kante suchen', $wpdb->prepare(
            "SELECT h.* FROM {$schatten} h
             INNER JOIN (
                 SELECT id, MAX(version) AS version FROM {$schatten} WHERE edge_id = %d GROUP BY id
             ) neuste ON neuste.id = h.id AND neuste.version = h.version
             WHERE h.edge_id = %d AND h.deleted = 1
               AND NOT EXISTS (SELECT 1 FROM {$lebend} l WHERE l.id = h.id)",
            $edgeId,
            $edgeId
        ));

        foreach ($zeilen as $zeile) {
            $spalten = [];

            foreach ($zeile as $name => $wert) {
                if (! in_array($name, Schema::SHADOW_ONLY, true)) {
                    $spalten[$name] = $wert;
                }
            }

            $spalten['version'] = (int) $zeile['version'] + 1;

            $wpdb->insert($lebend, $spalten, array_fill(0, count($spalten), '%s'));

            if ($wpdb->last_error !== '') {
                throw new \RuntimeException(
                    'Eine Wertzeile der geparkten Kante liess sich nicht zurückholen: ' . $wpdb->last_error
                );
            }
        }
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

        $rows = Query::rows('Kanten am Knoten lesen', $wpdb->prepare(
            "SELECT * FROM " . Schema::table("relations") . "
             WHERE from_node_id IN ({$places}) OR to_node_id IN ({$places})",
            ...[...$ids, ...$ids]
        ));

        return array_map($this->hydrate(...), $rows ?: []);
    }

    public function purgeEdgesTouching(int $nodeId): void
    {
        global $wpdb;

        // ⚠️ *Erst in den Schatten ([D-536](../../../docs/NewConcept/90-decision-log.md)): eine Kante,
        // die verschwindet, nimmt sonst mit, **warum** sie da war.*
        Shadow::keep('relations', 'from_node_id = %d OR to_node_id = %d', [$nodeId, $nodeId], true);

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('relations') . ' WHERE from_node_id = %d OR to_node_id = %d',
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

        $rows = Query::rows('Einstellungsdatensaetze der Kanten lesen', $wpdb->prepare(
            'SELECT id, settings_record_id, target_settings_record_id FROM ' . Schema::table('relations')
                . " WHERE id IN ($slots)",
            ...$edgeIds
        ));

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
            (int) $row['from_node_id'],
            (int) $row['to_node_id'],
            (string) $row['kind'],
            (string) $row['name'],
            (int) $row['sort_order'],
            isset($row['parked_by_group_id']) ? (int) $row['parked_by_group_id'] : null,
            (bool) ($row['hide'] ?? false),
            (string) ($row['multiplicity'] ?? '1..1'),
        );
    }
}
