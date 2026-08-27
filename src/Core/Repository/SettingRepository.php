<?php declare(strict_types=1);

namespace Taxmod\Core\Repository;

use Taxmod\Core\Model\Setting;

/**
 * Storage for settings, stated as the walk needs it.
 *
 * ⚠️ **The whole chain is loaded in one call** (D-014). The walk is installation → model root →
 * ancestors → node → use site, and asking link by link would be one query per level — the
 * recursion `CD-7` forbids. The chain's ids are known before the first query, because they come
 * out of the node's path.
 *
 * ⚠️ **Storage is sparse** (D-015): only what differs is written, so a change at the base
 * reaches every use site that did not override it. That is also why {@see forget()} exists as
 * its own operation — a row that disappears means *inherit again*, while a row holding nothing
 * means *deliberately nothing here* (D-266).
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
interface SettingRepository
{
    /**
     * Every setting belonging to any of these owners, in one statement.
     *
     * @param list<int> $ownerIds
     *
     * @return list<Setting>
     */
    public function forOwners(array $ownerIds): array;

    /** Write or replace one setting. */
    public function put(Setting $setting): void;

    /**
     * Remove a setting so that it is inherited again.
     *
     * ⚠️ **Not the same as storing nothing** (D-266). This is the *reset* half, and it must be
     * an explicit act rather than the side effect of clearing a field.
     */
    /**
     * ⚠️ *`path` addresses **which place** the row answered for — empty is the owner itself
     * ([D-409](../../../docs/NewConcept/90-decision-log.md)).*
     */
    public function forget(int $ownerId, string $key, string $path = ''): void;

    /**
     * Every row these owners hold, gone — the writing side of a purge.
     *
     * ⚠️ **This exists because deleting a node used to leave its settings behind**, which is how
     * 720 rows came to belong to owners that no longer existed (list row 28). *A node and what it
     * says about itself go together or the tidy-up is not one.*
     *
     * @param  list<int> $ownerIds
     * @return int       How many rows went, so an act can say what it did.
     */
    public function forgetOwners(array $ownerIds): int;

    /** Everything one owner holds — for a screen showing what was set **here**. */
    public function ownedBy(int $ownerId): array;
}
