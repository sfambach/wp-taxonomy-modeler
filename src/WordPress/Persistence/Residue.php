<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\LabelRepository;
use Taxmod\Core\Repository\SettingRepository;

/**
 * What deliberate non-tidying left behind — **measured in one place, removed in the same place**.
 *
 * [D-247](../../../docs/NewConcept/90-decision-log.md) names three sources and no more:
 * [D-156](../../../docs/NewConcept/90-decision-log.md)'s **orphaned overrides**,
 * [D-159](../../../docs/NewConcept/90-decision-log.md)'s **values whose edge is gone**, and **nodes
 * with no connections any more**. *Each of the three was decided as «leave it alone rather than tidy
 * it silently», which is right at the moment of the change and leaves residue over years.*
 *
 * ```mermaid
 * flowchart LR
 *   Q["this · the queries"] --> C["the Cleanup screen"]
 *   Q --> S["scripts/dev/orphans-*.php"]
 * ```
 *
 * ⚠️ **It exists because the same queries were already written twice on the command line.**
 * *`orphans-check.php` measures and `orphans-clean.php` removes; a screen would have been a third
 * copy, and `CLAUDE.md` forbids exactly that — «one place owns each piece of state». **All three
 * callers ask this class now**, so a correction to the query reaches every one of them.*
 *
 * ⚠️ **At the boundary and not in the core**, because every one of these questions is a question
 * about **rows** — `NOT EXISTS`, across tables the core has no interfaces for (`CD-1`). *A residue row
 * names an owner that no longer exists, so there is nothing for a repository of objects to return.*
 *
 * ⚠️ **The installation identity is not an orphan and every naive query says it is.** *It is an
 * identity with no node and no relation behind it
 * ([D-079](../../../docs/NewConcept/90-decision-log.md)) and its rows are the declared defaults for
 * the switches ([D-404](../../../docs/NewConcept/90-decision-log.md)). A sweep that took it would
 * take the answer to «what does `persistent` mean when nobody said» with it.*
 *
 * ⚠️ **Every removal re-measures before it acts.** *An id arrives from a form, and «this owner is
 * gone» is a fact about the database and not about the form. So each `forget…` asks again and removes
 * nothing when the answer changed in the meantime — which is also what makes a double-click harmless.*
 *
 * @see docs/NewConcept/20-interaction.md
 */
final class Residue
{
    /**
     * What `owner_kind` says when neither `node` nor `relation` is true any more.
     *
     * ⚠️ **A fourth kind, and it is an assumption rather than a decision.** *{@see \Taxmod\Core\Service\Settings}
     * already needed a third — `installation` — and its comment says why the honest answer matters:
     * «the first version of this line called that a `relation`, which was simply a lie.» **An orphaned
     * override names an owner that is neither**, and the only two things known about it are its id and
     * that nothing answers for it. Calling it a node would be the same lie one step further on.*
     */
    private const KIND_GONE = 'gone';

    public function __construct(
        private readonly FrameworkNodes $framework,
        private readonly SettingRepository $settings,
        private readonly LabelRepository $labels,
        private readonly Changelog $changelog,
    ) {
    }

    /**
     * Owners of settings that no node and no relation answers for — [D-156](../../../docs/NewConcept/90-decision-log.md).
     *
     * @return array<int,int> owner id ⇒ how many rows it still holds
     */
    public function orphanedSettings(): array
    {
        return $this->ownersWithoutOwner('settings');
    }

    /**
     * The same for labels.
     *
     * ⚠️ *[D-247](../../../docs/NewConcept/90-decision-log.md) says «settings that broke because
     * something was deleted» and names labels nowhere. They are residue of the same shape, and
     * `orphans-check.php` has measured them since row 28 — so the query lives here, and **whether the
     * screen offers them is a decision the owner has not made**.*
     *
     * @return array<int,int> owner id ⇒ how many rows it still holds
     */
    public function orphanedLabels(): array
    {
        return $this->ownersWithoutOwner('labels');
    }

    /**
     * Record values whose edge is gone — [D-159](../../../docs/NewConcept/90-decision-log.md).
     *
     * ⚠️ **D-159 is the decision *not* to touch these while drawing**: *«those are simply not drawn,
     * and **not touched**: they stay in `record_values` … because removing them is a migration
     * ([D-061](../../../docs/NewConcept/90-decision-log.md)) and migrating is not the job of
     * drawing.»* *This class is not drawing. It is the surface D-247 asks for, where the same rows are
     * removed **deliberately** — which is the only place that sentence leaves open.*
     *
     * @return array<int,int> edge id ⇒ how many values still name it
     */
    public function valuesWithoutEdge(): array
    {
        $rows = $this->rows(
            'SELECT v.edge_id AS owner, COUNT(*) AS rows_held FROM ' . Schema::table('record_values') . ' v
             WHERE NOT EXISTS (SELECT 1 FROM ' . Schema::table('relations') . ' r WHERE r.id = v.edge_id)
             GROUP BY v.edge_id
             ORDER BY v.edge_id ASC'
        );

        return $this->countsByOwner($rows);
    }

    /**
     * Nodes that hang on nothing at all — the third of [D-247](../../../docs/NewConcept/90-decision-log.md)'s sources.
     *
     * ⚠️ **No edge in either direction, and both directions matter.** *A node with no **parent** edge
     * is not necessarily residue — the root has none by construction and is the top of everything. A
     * node with no edge **touching** it is in no tree, holds no attribute and is pointed at by
     * nothing: it is what [D-123](../../../docs/NewConcept/90-decision-log.md)'s purge leaves when an
     * edge went and the node did not.*
     *
     * ⚠️ *Measured 2026-08-28 on the real model: **0**. That is the answer a repair surface should
     * usually give, and it is why the empty case had to read as good news rather than as an empty
     * table.*
     *
     * @return list<Node>
     */
    public function nodesWithoutConnections(): array
    {
        $rows = $this->rows(
            'SELECT n.id, n.version, n.name, n.path FROM ' . Schema::table('nodes') . ' n
             WHERE NOT EXISTS (
                 SELECT 1 FROM ' . Schema::table('relations') . ' r
                 WHERE r.from_id = n.id OR r.to_id = n.id
             )
             ORDER BY n.id ASC'
        );

        return array_map(
            static fn (object $row): Node => Node::fromStorage(
                (int) $row->id,
                (int) $row->version,
                (string) $row->name,
                (string) $row->path
            ),
            $rows
        );
    }

    /**
     * Remove one gone owner's settings, and say how many went.
     *
     * ⚠️ *The removal itself is {@see SettingRepository::forgetOwners()} — the same method
     * `orphans-clean.php` uses, so nothing here writes its own `DELETE`.*
     */
    public function forgetOrphanedSettings(int $ownerId): int
    {
        if (! array_key_exists($ownerId, $this->orphanedSettings())) {
            return 0;
        }

        $gone = $this->settings->forgetOwners([$ownerId]);

        $this->record($ownerId, self::KIND_GONE, 'settings removed', $gone);

        return $gone;
    }

    public function forgetOrphanedLabels(int $ownerId): int
    {
        if (! array_key_exists($ownerId, $this->orphanedLabels())) {
            return 0;
        }

        $gone = $this->labels->forgetOwners([$ownerId]);

        $this->record($ownerId, self::KIND_GONE, 'labels removed', $gone);

        return $gone;
    }

    /** Remove the values of an edge that no longer exists, and say how many went. */
    public function forgetValuesOfEdge(int $edgeId): int
    {
        global $wpdb;

        if (! array_key_exists($edgeId, $this->valuesWithoutEdge())) {
            return 0;
        }

        $gone = (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('record_values') . ' WHERE edge_id = %d',
            $edgeId
        ));

        $this->refuseBrokenQuery('record_values wegräumen');

        // ⚠️ *`relation` and not {@see self::KIND_GONE}: the id came out of `record_values.edge_id`, so
        // what it **was** is known even though the row it named is not there any more.*
        $this->record($edgeId, 'relation', 'values removed', $gone);

        return $gone;
    }

    /**
     * Remove a node that hangs on nothing, with what belonged to it.
     *
     * ⚠️ **Its ids and its changelog stay** ([D-339](../../../docs/NewConcept/90-decision-log.md),
     * [D-065](../../../docs/NewConcept/90-decision-log.md)) — the same promise the trash's «clear»
     * makes, and for the same reason: an id is never handed out twice and the history still says what
     * was there.
     *
     * ⚠️ *Settings and labels go first. They hang off the node, and a row whose owner is gone is the
     * very residue this screen exists to remove — creating more of it while removing some would be a
     * repair that produces work.*
     *
     * @return array{settings: int, labels: int}|null Null when this id is not a disconnected node —
     *         **which is not the same as a node that owned nothing.** *Both would be two zeroes, and a
     *         page that reported «removed 0 and 0» for a refusal would be reporting an act that never
     *         happened.*
     */
    public function purgeNodeWithoutConnections(int $id): ?array
    {
        global $wpdb;

        $standing = array_filter(
            $this->nodesWithoutConnections(),
            static fn (Node $node): bool => $node->id === $id
        );

        if ($standing === []) {
            return null;
        }

        $gone = [
            'settings' => $this->settings->forgetOwners([$id]),
            'labels'   => $this->labels->forgetOwners([$id]),
        ];

        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('nodes') . ' WHERE id = %d', $id));

        $this->refuseBrokenQuery('Knoten entfernen');

        $this->changelog->record(
            $id,
            'node',
            'purged',
            'no connections',
            sprintf('%d settings, %d labels', $gone['settings'], $gone['labels'])
        );

        return $gone;
    }

    /**
     * Owners a table names that are neither a node, nor an edge, nor the installation.
     *
     * @return array<int,int> owner id ⇒ rows held
     */
    private function ownersWithoutOwner(string $table): array
    {
        global $wpdb;

        $rows = $this->rows($wpdb->prepare(
            'SELECT t.owner_id AS owner, COUNT(*) AS rows_held FROM ' . Schema::table($table) . ' t
             WHERE t.owner_id <> %d
               AND NOT EXISTS (SELECT 1 FROM ' . Schema::table('nodes') . ' n WHERE n.id = t.owner_id)
               AND NOT EXISTS (SELECT 1 FROM ' . Schema::table('relations') . ' r WHERE r.id = t.owner_id)
             GROUP BY t.owner_id
             ORDER BY t.owner_id ASC',
            $this->framework->installationId()
        ));

        return $this->countsByOwner($rows);
    }

    /**
     * @param list<object> $rows
     *
     * @return array<int,int>
     */
    private function countsByOwner(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->owner] = (int) $row->rows_held;
        }

        return $counts;
    }

    /**
     * One statement, and it never returns an empty result for a broken query.
     *
     * ⚠️ **`$wpdb` answers a broken query with an empty result**, and «no residue» is exactly what
     * this screen most wants to say — *so a typo in a `NOT EXISTS` would read as good news. That
     * mistake was reported to the owner as a fact about his model on 2026-08-26; it is checked after
     * **every** statement here, not once at the end.*
     *
     * @return list<object>
     */
    private function rows(string $sql): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($sql);

        $this->refuseBrokenQuery('Rückstand messen');

        return $rows ?: [];
    }

    private function refuseBrokenQuery(string $doing): void
    {
        global $wpdb;

        if ($wpdb->last_error !== '') {
            throw new \RuntimeException($doing . ': ' . $wpdb->last_error);
        }
    }

    /**
     * One line of history per removal.
     *
     * ⚠️ *[D-081](../../../docs/NewConcept/90-decision-log.md) wants every object to have at least one
     * entry, and [D-065](../../../docs/NewConcept/90-decision-log.md) lets the changelog outlive what
     * it refers to — so removing residue is recorded **against the owner that is already gone**, which
     * is the only place the act can be described.*
     */
    private function record(int $ownerId, string $kind, string $what, int $gone): void
    {
        $this->changelog->record(
            $ownerId,
            $kind,
            $what,
            sprintf('%d rows', $gone),
            null
        );
    }
}
