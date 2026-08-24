<?php declare(strict_types=1);

namespace Taxmod\Core\Repository;

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
}
