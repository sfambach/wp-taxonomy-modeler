<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * When a subject appeared, when it last moved, and who did it.
 *
 * The owner asked for these on the page: *constants like path, id and version, creation, last
 * change, change owner.*
 *
 * ⚠️ **Read out of the changelog and stored nowhere else** — no `created_at` column on `nodes`, no
 * `modified_by`. The changelog already holds every act with its time and its user
 * ([D-065](../../../docs/NewConcept/90-decision-log.md)), so a column beside it would be the same
 * fact twice and the two would drift the first time a row was written without the column being
 * touched. *`CD` puts it plainly: one place owns each piece of state, everything else derives.*
 *
 * ```mermaid
 * flowchart LR
 *   C["changelog · every act"] --> F["first · created"]
 *   C --> L["last · changed"]
 * ```
 *
 * ⚠️ **A user id and not a name.** Who user 1 *is* belongs to WordPress, and the core has no idea
 * (`CD-1`, [D-171](../../../docs/NewConcept/90-decision-log.md)) — so the boundary turns the number
 * into a name, exactly as it turns a nonce into a form.
 *
 * ⚠️ *`null` throughout is a real answer: a node seeded before the changelog existed has no history,
 * and saying **nothing** about it is better than showing the moment somebody first happened to touch
 * it as its birthday.*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class ChangeSummary
{
    public function __construct(
        public readonly ?string $createdAt = null,
        public readonly ?int $createdBy = null,
        public readonly ?string $changedAt = null,
        public readonly ?int $changedBy = null,
        public readonly ?string $lastAct = null,
    ) {
    }

    /** Whether anything is known at all — a node older than the changelog answers no. */
    public function isKnown(): bool
    {
        return $this->createdAt !== null || $this->changedAt !== null;
    }
}
