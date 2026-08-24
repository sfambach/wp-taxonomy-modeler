<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Repository\Clock;

/**
 * Frozen history, one row per change.
 *
 * ⚠️ **A machine change is recorded as the machine** (D-296): when no human is behind the
 * request — cron, WP-CLI, an import — `by_user_id` stays null rather than borrowing whichever
 * administrator was logged in. A wrong name in the history is worse than no name.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class WpdbChangelog implements Changelog
{
    public function __construct(private readonly Clock $clock)
    {
    }

    public function record(
        int $ownerId,
        string $ownerKind,
        string $what,
        ?string $before,
        ?string $after,
    ): void {
        global $wpdb;

        $wpdb->insert(
            Schema::table('changelog'),
            [
                'owner_id'     => $ownerId,
                'owner_kind'   => $ownerKind,
                'at'           => $this->clock->now()->format('Y-m-d H:i:s'),
                'by_user_id'   => $this->human(),
                'what'         => $what,
                'before_state' => $before,
                'after_state'  => $after,
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
    }

    private function human(): ?int
    {
        if (wp_doing_cron() || (defined('WP_CLI') && WP_CLI)) {
            return null;
        }

        $id = get_current_user_id();

        return $id > 0 ? $id : null;
    }
}
