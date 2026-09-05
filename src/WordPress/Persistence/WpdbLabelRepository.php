<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Repository\LabelRepository;

/**
 * Beschriftungen in zwei Tabellen: `labels` trägt das Sprachunabhängige, `label_texts` die Texte je
 * Sprache — und Knoten und Kanten zeigen mit `label_id` darauf.
 *
 * ```mermaid
 * flowchart LR
 *   N[(nodes.label_id)] --> L[(labels)]
 *   R[(relations.label_id)] --> L
 *   L --> T[(label_texts · eine Zeile je Sprache)]
 * ```
 *
 * ⚠️ **Der Verweis zeigt in die Gegenrichtung, und das ist der Kern des Entwurfs**
 * ([D-580](../../../docs/NewConcept/90-decision-log.md)). *Zeigte die Labeltabelle auf ihren
 * Gegenstand, bräuchte sie je neuer Art eine weitere Spalte — `node_id`, `relation_id`, dann
 * `setting_id`. So bekommt jede Tabelle **ihre eigene `label_id`**, und jeder Fremdschlüssel ist echt
 * und einspaltig.*
 *
 * ⚠️ **Nach aussen bleibt eine Beschriftung eine Zeile je Rolle** — *flach gespeichert, hoch
 * gelesen.* *Die Rollen sind Spalten ([D-598](../../../docs/NewConcept/90-decision-log.md),
 * [D-646](../../../docs/NewConcept/90-decision-log.md)), und diese Klasse ist die einzige Stelle, an
 * der jemand das wissen muss.*
 *
 * ⚠️ **Sparse by nature.** Nur was jemand geschrieben hat, steht da; die Rückfallkette liefert den
 * Rest, und ihr letzter Schritt ist die Rolle `name` in der Standardsprache — die ist immer da, weil
 * ein Knoten ohne Namen nicht entsteht (D-022).
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class WpdbLabelRepository implements LabelRepository
{
    /** Welche Spalte in `label_texts` welche Rolle trägt. */
    public static function columnFor(SeededRole $role): string
    {
        return 'text_' . $role->value;
    }

    public function forOwners(array $ownerIds, IdentitySpace $ownerKind): array
    {
        global $wpdb;

        if ($ownerIds === []) {
            return [];
        }

        $places = implode(',', array_fill(0, count($ownerIds), '%d'));
        $owner  = self::ownerTable($ownerKind);
        $texts  = Schema::table('label_texts');

        $spalten = implode(', ', array_map(
            static fn (SeededRole $r): string => 't.' . self::columnFor($r),
            SeededRole::cases()
        ));

        $rows = Query::rows('Texte der Eigentuemer lesen', $wpdb->prepare(
            "SELECT o.id AS owner_id, l.version, t.locale, t.number, {$spalten}
             FROM {$owner} o
             JOIN " . Schema::table('labels') . " l ON l.id = o.label_id
             JOIN {$texts} t ON t.label_id = l.id
             WHERE o.id IN ({$places})",
            ...array_map(intval(...), $ownerIds)
        ));

        $gefunden = [];

        foreach ($rows ?: [] as $row) {
            foreach (SeededRole::cases() as $role) {
                $text = $row[self::columnFor($role)] ?? null;

                if ($text === null || (string) $text === '') {
                    continue;
                }

                $gefunden[] = new Label(
                    (int) $row['owner_id'],
                    $ownerKind,
                    $role,
                    (string) $row['number'],
                    (string) $row['locale'],
                    (string) $text,
                    (int) $row['version'],
                );
            }
        }

        return $gefunden;
    }

    /**
     * Schreiben heisst: die Spalte dieser Rolle in der Zeile dieser Sprache setzen.
     *
     * ⚠️ **Die Version zählt am Träger und nicht an der Textzeile** ([D-640](../../../docs/NewConcept/90-decision-log.md)).
     * *Sie ist die Nummer **der Beschriftung**; eine Beschriftung ist das Ding, an dem der Eigentümer
     * hängt, und nicht eine ihrer sechs Spalten.*
     *
     * ⚠️ **Am Knoten entsteht die Beschriftungszeile hier, wenn es noch keine gibt** — *`label_id` ist
     * dort Pflicht (D-580), und ein Knoten, dessen Beschriftung fehlte, hätte keinen Namen mehr.*
     */
    public function put(Label $label): void
    {
        global $wpdb;

        $labelId = $this->labelIdFor($label->ownerId, $label->ownerKind, true);
        $spalte  = self::columnFor($label->role);
        $texts   = Schema::table('label_texts');

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$texts} (label_id, locale, number, {$spalte}) VALUES (%d, %s, %s, %s)
             ON DUPLICATE KEY UPDATE {$spalte} = VALUES({$spalte})",
            $labelId,
            $label->locale,
            $label->number,
            $label->text
        ));

        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Eine Beschriftung liess sich nicht schreiben: ' . $wpdb->last_error);
        }

        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::table('labels') . ' SET version = version + 1 WHERE id = %d',
            $labelId
        ));
    }

    public function forget(int $ownerId, IdentitySpace $ownerKind, SeededRole $role, string $number, string $locale): void
    {
        global $wpdb;

        $labelId = $this->labelIdFor($ownerId, $ownerKind, false);

        if ($labelId === 0) {
            return;
        }

        $spalte = self::columnFor($role);
        $texts  = Schema::table('label_texts');

        $wpdb->query($wpdb->prepare(
            "UPDATE {$texts} SET {$spalte} = NULL WHERE label_id = %d AND locale = %s AND number = %s",
            $labelId,
            $locale,
            $number
        ));

        // ⚠️ *Eine Zeile, in der keine Spalte mehr etwas sagt, ist Abfall
        // ([D-384](../../../docs/NewConcept/90-decision-log.md): «leer heisst vergiss die Zeile»).*
        $leer = implode(' AND ', array_map(
            static fn (SeededRole $r): string => self::columnFor($r) . " IS NULL",
            SeededRole::cases()
        ));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$texts} WHERE label_id = %d AND locale = %s AND number = %s AND {$leer}",
            $labelId,
            $locale,
            $number
        ));

        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::table('labels') . ' SET version = version + 1 WHERE id = %d',
            $labelId
        ));
    }

    /**
     * Every row these owners hold, gone — one statement per table, never a loop (`CD-7`).
     *
     * ⚠️ *Ids are cast to `int` by this method, so the list carries no user input — and it is still
     * assembled with placeholders rather than glued in (`CD-6`).*
     *
     * ⚠️ **Die Texte, die Beschriftung und der Verweis darauf — alle drei**, *sonst bliebe eine
     * `label_id` stehen, die auf nichts zeigt. Der Aufrufer räumt gerade den Eigentümer weg; was er
     * stehenliesse, fände der nächste Aufräumlauf als Waise.*
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
        $owner  = self::ownerTable($ownerKind);

        $labelIds = Query::column('Beschriftungsnummern der Eigentuemer lesen', $wpdb->prepare(
            "SELECT label_id FROM {$owner} WHERE label_id IS NOT NULL AND label_id > 0 AND id IN ({$places})",
            ...$ids
        ));

        if ($labelIds === []) {
            return 0;
        }

        $labelIds = array_map(intval(...), $labelIds);
        $slots    = implode(',', array_fill(0, count($labelIds), '%d'));

        $weg = (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('label_texts') . " WHERE label_id IN ({$slots})",
            ...$labelIds
        ));

        $wpdb->query($wpdb->prepare(
            "UPDATE {$owner} SET label_id = " . ($ownerKind === IdentitySpace::Relation ? 'NULL' : '0')
            . " WHERE label_id IN ({$slots})",
            ...$labelIds
        ));

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('labels') . " WHERE id IN ({$slots})",
            ...$labelIds
        ));

        return $weg;
    }

    /**
     * Die Beschriftungszeile eines Eigentümers — angelegt, wenn sie fehlt und gebraucht wird.
     *
     * ⚠️ *`0` heisst «es gibt keine», nicht «es ist keine da» — der Unterschied zählt beim Vergessen:
     * dort wird nichts angelegt, nur um es gleich zu leeren.*
     */
    private function labelIdFor(int $ownerId, IdentitySpace $ownerKind, bool $anlegen): int
    {
        global $wpdb;

        $owner = self::ownerTable($ownerKind);

        $labelId = (int) Query::value(
            'Beschriftungsnummer des Eigentuemers lesen',
            $wpdb->prepare("SELECT label_id FROM {$owner} WHERE id = %d", $ownerId)
        );

        if ($labelId !== 0 || ! $anlegen) {
            return $labelId;
        }

        $wpdb->insert(Schema::table('labels'), ['version' => 1, 'owner_kind' => $ownerKind->value], ['%d', '%s']);

        $labelId = (int) $wpdb->insert_id;

        if ($labelId === 0) {
            throw new \RuntimeException('Es liess sich keine Beschriftungszeile anlegen: ' . $wpdb->last_error);
        }

        $wpdb->update($owner, ['label_id' => $labelId], ['id' => $ownerId], ['%d'], ['%d']);

        return $labelId;
    }

    private static function ownerTable(IdentitySpace $ownerKind): string
    {
        return Schema::table($ownerKind === IdentitySpace::Relation ? 'relations' : 'nodes');
    }
}
