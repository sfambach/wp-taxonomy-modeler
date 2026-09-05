<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Label;
use Taxmod\Core\Repository\LabelRepository;

/**
 * Labels in a table of our own, one row per owner, path, role, number and locale.
 *
 * ⚠️ **Sparse by nature.** Only what somebody actually wrote is stored; the fallback chain
 * supplies the rest, and the last step is the node's own name, which always exists (D-022). A
 * screen therefore never has an empty cell where a name should be.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class WpdbLabelRepository implements LabelRepository
{
    public function forOwners(array $ownerIds): array
    {
        global $wpdb;

        if ($ownerIds === []) {
            return [];
        }

        $places = implode(',', array_fill(0, count($ownerIds), '%d'));

        $rows = Query::rows('Texte der Eigentuemer lesen', $wpdb->prepare(
            'SELECT owner_id, path, role_id, number, locale, text FROM ' . Schema::table('labels') . "
             WHERE owner_id IN ({$places})",
            array_map(intval(...), $ownerIds)
        ));

        return array_map(
            static fn (array $r): Label => new Label(
                (int) $r['owner_id'],
                (string) $r['path'],
                (int) $r['role_id'],
                (string) $r['number'],
                (string) $r['locale'],
                (string) $r['text'],
            ),
            $rows ?: []
        );
    }

    public function put(Label $label): void
    {
        global $wpdb;

        $wpdb->replace(
            Schema::table('labels'),
            [
                'owner_id' => $label->ownerId,
                'path'     => $label->path,
                'role_id'  => $label->roleId,
                'number'   => $label->number,
                'locale'   => $label->locale,
                'text'     => $label->text,
            ],
            ['%d', '%s', '%d', '%s', '%s', '%s']
        );
    }

    public function forget(int $ownerId, string $path, int $roleId, string $number, string $locale): void
    {
        global $wpdb;

        $wpdb->delete(
            Schema::table('labels'),
            ['owner_id' => $ownerId, 'path' => $path, 'role_id' => $roleId, 'number' => $number, 'locale' => $locale],
            ['%d', '%s', '%d', '%s', '%s']
        );
    }

    /**
     * Every row these owners hold, gone — one statement, never a loop (`CD-7`).
     *
     * ⚠️ *Ids are cast to `int` by this method, so the list carries no user input — and it is still
     * assembled with placeholders rather than glued in (`CD-6`).*
     *
     * @param list<int> $ownerIds
     */
    public function forgetOwners(array $ownerIds): int
    {
        global $wpdb;

        $ids = array_values(array_unique(array_map(intval(...), $ownerIds)));

        if ($ids === []) {
            return 0;
        }

        $places = implode(",", array_fill(0, count($ids), "%d"));

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM " . Schema::table("labels") . " WHERE owner_id IN ({$places})",
            ...$ids
        ));
    }
}
