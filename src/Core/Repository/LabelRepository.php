<?php declare(strict_types=1);

namespace Taxmod\Core\Repository;

use Taxmod\Core\Model\Label;

/**
 * Storage for labels.
 *
 * ⚠️ **Everything an owner has, in one call.** The fallback chain tries several roles and
 * numbers in turn, and asking the database once per attempt would be four queries to draw one
 * word — on a screen showing a hundred rows, four hundred (`CD-7`).
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
interface LabelRepository
{
    /**
     * @param list<int> $ownerIds
     *
     * @return list<Label>
     */
    public function forOwners(array $ownerIds): array;

    public function put(Label $label): void;

    /** Remove one label so the chain falls through to the next step. */
    public function forget(int $ownerId, string $path, int $roleId, string $number, string $locale): void;
}
