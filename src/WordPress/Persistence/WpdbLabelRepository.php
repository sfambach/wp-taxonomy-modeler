<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Repository\LabelRepository;

/**
 * Labels in a table of our own, one row per owner, **space**, path, role, number and locale.
 *
 * ⚠️ **Sparse by nature.** Only what somebody actually wrote is stored; the fallback chain
 * supplies the rest, and the last step is the node's own name, which always exists (D-022). A
 * screen therefore never has an empty cell where a name should be.
 *
 * ⚠️ **`owner_kind` steht seit Fassung 31 neben `owner_id`** (`INF-035`,
 * [D-164](../../../docs/NewConcept/90-decision-log.md), [D-597](../../../docs/NewConcept/90-decision-log.md)).
 * *Ein Label hängt an einem Knoten **oder** an einer Kante ([D-410](../../../docs/NewConcept/90-decision-log.md));
 * seit jede Tabelle ihren eigenen Id-Raum hat (TASK-004) und ein Knoten keine Vererbungskante mehr
 * anlegt ([D-581](../../../docs/NewConcept/90-decision-log.md)), laufen die beiden Zähler verschieden
 * schnell und treffen sich. **Ohne die Spalte bekam eine frische Kante am 2026-09-05 sechs Zeilen
 * statt einer.***
 *
 * ⚠️ **`version` ist die Zeilennummer und wird hier gezählt, nicht vom Aufrufer gesetzt**
 * ([D-634](../../../docs/NewConcept/90-decision-log.md)). *Darum kein `replace()` mehr: das war ein
 * Löschen samt Neuanlage, und es hätte die Nummer bei jedem Speichern auf 1 zurückgesetzt.*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class WpdbLabelRepository implements LabelRepository
{
    public function forOwners(array $ownerIds, IdentitySpace $ownerKind): array
    {
        global $wpdb;

        if ($ownerIds === []) {
            return [];
        }

        $places = implode(',', array_fill(0, count($ownerIds), '%d'));

        $rows = Query::rows('Texte der Eigentuemer lesen', $wpdb->prepare(
            'SELECT owner_id, owner_kind, path, role_id, number, locale, text, version
             FROM ' . Schema::table('labels') . "
             WHERE owner_kind = %s AND owner_id IN ({$places})",
            $ownerKind->value,
            ...array_map(intval(...), $ownerIds)
        ));

        return array_map(
            static fn (array $r): Label => new Label(
                (int) $r['owner_id'],
                IdentitySpace::from((string) $r['owner_kind']),
                (string) $r['path'],
                (int) $r['role_id'],
                (string) $r['number'],
                (string) $r['locale'],
                (string) $r['text'],
                (int) $r['version'],
            ),
            $rows ?: []
        );
    }

    /**
     * Schreiben heisst: die vorhandene Zeile eine Version weiter, oder eine neue mit Version 1.
     *
     * ⚠️ *Der Aufrufer entscheidet nicht über die Nummer — {@see \Taxmod\Core\Service\Labels::put()}
     * schreibt ohnehin nur, wenn sich der Text geändert hat, also ist jedes `UPDATE` hier eine echte
     * Änderung und hebt die Version.*
     */
    public function put(Label $label): void
    {
        global $wpdb;

        $table = Schema::table('labels');

        $geaendert = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET text = %s, version = version + 1
             WHERE owner_id = %d AND owner_kind = %s AND path = %s
               AND role_id = %d AND number = %s AND locale = %s",
            $label->text,
            $label->ownerId,
            $label->ownerKind->value,
            $label->path,
            $label->roleId,
            $label->number,
            $label->locale
        ));

        if ($geaendert > 0) {
            return;
        }

        $wpdb->insert(
            $table,
            [
                'owner_id'   => $label->ownerId,
                'owner_kind' => $label->ownerKind->value,
                'path'       => $label->path,
                'role_id'    => $label->roleId,
                'number'     => $label->number,
                'locale'     => $label->locale,
                'text'       => $label->text,
                'version'    => 1,
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d']
        );
    }

    public function forget(int $ownerId, IdentitySpace $ownerKind, string $path, int $roleId, string $number, string $locale): void
    {
        global $wpdb;

        $wpdb->delete(
            Schema::table('labels'),
            [
                'owner_id'   => $ownerId,
                'owner_kind' => $ownerKind->value,
                'path'       => $path,
                'role_id'    => $roleId,
                'number'     => $number,
                'locale'     => $locale,
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s']
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
    public function forgetOwners(array $ownerIds, IdentitySpace $ownerKind): int
    {
        global $wpdb;

        $ids = array_values(array_unique(array_map(intval(...), $ownerIds)));

        if ($ids === []) {
            return 0;
        }

        $places = implode(',', array_fill(0, count($ids), '%d'));

        return (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('labels') . " WHERE owner_kind = %s AND owner_id IN ({$places})",
            $ownerKind->value,
            ...$ids
        ));
    }
}
