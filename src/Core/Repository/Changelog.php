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
     * @param int         $ownerId   Node or relation id, from the model identity space.
     * @param string      $ownerKind `node` or `relation` — stored alongside because the
     *                               changelog outlives what it refers to (D-065).
     * @param string      $what      Short verb: `created`, `renamed`, `moved`, `parked`.
     * @param string|null $before    The previous state, or null when there was none.
     * @param string|null $after     The new state, or null when the object is gone.
     */
    public function record(
        int $ownerId,
        string $ownerKind,
        string $what,
        ?string $before,
        ?string $after,
    ): void;

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
