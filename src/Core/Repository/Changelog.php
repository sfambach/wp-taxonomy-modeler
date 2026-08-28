<?php declare(strict_types=1);

namespace Taxmod\Core\Repository;

use Taxmod\Core\Model\ChangeSummary;

/**
 * Frozen history. **Every object has at least one item**, because creation must be logged —
 * `creation_date` is read from here rather than stored twice (D-080, D-081).
 *
 * ⚠️ A machine change is recorded **as the machine**, never as whichever administrator happened
 * to be logged in (D-296). That is why `byUserId` is nullable rather than defaulted.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
interface Changelog
{
    /**
     * Open an act: everything recorded until {@see endAct()} belongs to **one change**.
     *
     * ⚠️ **The owner asked for this and the column was already there and grouping nothing**
     * ([list row 45](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)): *I think
     * we need a unique change number — whatever was changed in one change, edge, node, setting, if
     * they were changed together they should have one change number.* **Measured before the bracket:
     * 2282 rows across 1945 groups, 1609 of them holding a single row, and 0 of 1945 spanning more
     * than one kind of owner.** *A group id was handed out per **write** rather than per change.*
     *
     * ⚠️ **No new counter, so [D-348](../../../docs/NewConcept/90-decision-log.md) stands
     * untouched**: the group is still *the id of the act's first row*. This only says **which rows
     * belong to that first one** — the bracket holds no number of its own until a row arrives, which
     * is also why it returns nothing.
     *
     * ⚠️ **Re-entrant on purpose.** *A boundary act calls a service that calls another — `duplicate()`
     * creates a node, which records — and each of those may open a bracket of its own. Counting depth
     * makes the **outermost** one the act, which is the one a person performed.*
     */
    public function beginAct(): void;

    /** Close the innermost act; the outermost close ends the grouping. */
    public function endAct(): void;

    /**
     * @param int         $ownerId       Node or relation id, from the model identity space.
     * @param string      $ownerKind     `node` or `relation` — stored alongside because the
     *                                   changelog outlives what it refers to (D-065).
     * @param string      $what          Short verb: `created`, `renamed`, `moved`, `parked`.
     * @param string|null $before        The previous state, or null when there was none.
     * @param string|null $after         The new state, or null when the object is gone.
     * @param int|null    $changeGroupId The act this row belongs to; null starts a new one.
     *
     * @return int The change group — pass it to every further row of the same act (D-348).
     */
    public function record(
        int $ownerId,
        string $ownerKind,
        string $what,
        ?string $before,
        ?string $after,
        ?int $changeGroupId = null,
    ): int;

    /**
     * Write several rows under one bracket, in one go.
     *
     * ⚠️ **History has to be per object to be usable** — *these children moved* is not an answer,
     * *this child moved from here to there* is. Writing them one at a time would be the loop
     * `CD-7` forbids, so they go together.
     *
     * @param list<array{ownerId: int, ownerKind: string, what: string, before: ?string, after: ?string}> $rows
     *
     * @return int The change group they were written under.
     */
    public function recordMany(array $rows, ?int $changeGroupId = null): int;

    /**
     * The rows written under the same act as this owner's last row carrying `$what`.
     *
     * Used by a restore to find what fell with the node (D-347, D-348).
     *
     * @return list<array{ownerId: int, what: string, before: ?string, after: ?string}>
     */
    public function actAround(int $ownerId, string $what): array;

    /**
     * Where a node stood the last time it was parked, or null if it never was.
     *
     * ⚠️ **This makes the changelog load-bearing rather than decorative.** The old path is
     * written there and nowhere else — deliberately, so that no `parked_from` column duplicates
     * a fact (D-123, and the Package 1 assumptions). The price is that the frozen state's
     * **format** is now a contract: see {@see Changelog::record()}.
     */
    public function pathBeforeLastParking(int $ownerId): ?string;

    /**
     * When this subject appeared, when it last changed, and who did it.
     *
     * ⚠️ **Two rows, not the whole history.** The owner wants *creation, last change, change owner* on
     * the page, and reading every act to find two of them would be a query that grows with the age of
     * the installation — the first and the last are one statement each.
     *
     * ⚠️ *Null fields are a real answer: a node seeded before the changelog existed has no history,
     * and showing the moment somebody first touched it as its birthday would be a lie.*
     */
    public function summaryOf(int $ownerId): ChangeSummary;
}
