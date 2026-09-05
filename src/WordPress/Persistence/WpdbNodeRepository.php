<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Exception\ConcurrentChange;
use Taxmod\Core\Exception\NodeNotFound;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\FieldType;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Repository\NodeRepository;

/**
 * Nodes in a table of our own (AR-1), reached through `$wpdb`.
 *
 * ⚠️ **Every variable goes through `prepare()`** — `CD-6`, without exception, including the
 * `LIKE` patterns, whose `%` must be escaped with `esc_like()` first or a name containing a
 * percent sign silently widens the match.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class WpdbNodeRepository implements NodeRepository
{
    public function byId(int $id): Node
    {
        return $this->find($id) ?? throw NodeNotFound::withId($id);
    }

    public function find(int $id): ?Node
    {
        global $wpdb;

        $row = Query::row('Knoten lesen', $wpdb->prepare('SELECT id, version, name, path, field_type, implemented_by FROM ' . Schema::table('nodes') . ' WHERE id = %d', $id));

        return $row === null ? null : $this->hydrate($row);
    }

    public function byIds(array $ids): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        // ⚠️ The ids are cast to int above, but the placeholders are still built rather than
        // interpolated — `CD-6` has no exception for values that look safe.
        $slots = implode(',', array_fill(0, count($ids), '%d'));

        $rows = Query::rows('Knoten nach Ids lesen', $wpdb->prepare(
            'SELECT id, version, name, path, field_type, implemented_by FROM ' . Schema::table('nodes') . " WHERE id IN ($slots)",
            ...array_map(intval(...), $ids)
        ));

        $found = [];

        foreach ($rows ?: [] as $row) {
            $found[(int) $row['id']] = $this->hydrate($row);
        }

        return $found;
    }

    /**
     * ⚠️ **Die Id kommt aus dem `AUTO_INCREMENT` dieser Tabelle** (TASK-004). *`identities` ist
     * gestrichen; wer mit Id `0` ankommt, bekommt die nächste freie Nummer **dieses** Raums, und der
     * Pfad wird mit ihr nachgezogen ({@see Node::withAssignedId()}). Eine mitgebrachte Id bleibt,
     * wie sie ist — sonst könnte ein Wiederaufbau seine Nummern nicht zurückschreiben.*
     */
    public function add(Node $node): Node
    {
        global $wpdb;

        $spalten = [
            'version'        => $node->version,
            'name'           => $node->name,
            'path'           => $node->path,
            'field_type'     => $node->fieldType?->value,
            'implemented_by' => $node->implementedBy,
        ];
        $formate = ['%d', '%s', '%s', '%s', '%s'];

        if ($node->id !== 0) {
            $spalten = ['id' => $node->id, ...$spalten];
            $formate = ['%d', ...$formate];
        }

        $wpdb->insert(Schema::table('nodes'), $spalten, $formate);

        if ($node->id !== 0) {
            return $node;
        }

        $node = $node->withAssignedId((int) $wpdb->insert_id);

        // Der Pfad trug bis eben die 0 an letzter Stelle; er wird mit der vergebenen Id nachgezogen.
        $wpdb->update(
            Schema::table('nodes'),
            ['path' => $node->path],
            ['id' => $node->id],
            ['%s'],
            ['%d']
        );

        return $node;
    }

    public function save(Node $node, int $expectedVersion): void
    {
        global $wpdb;

        // The WHERE carries the expected version, so the guard is the write itself rather than
        // a read followed by a hopeful update (P4c).
        // ⚠️ *`kind` fährt mit, sonst hätte ein Umbenennen die Sorte gelöscht — dieselbe Falle, die
        // `path` hier schon hat.*
        //
        // ⚠️ **`update()` und nicht `query(prepare(...))`, und der Grund ist gemessen:
        // `$wpdb->prepare('kind = %s', null)` ergibt `kind = ''` — eine leere Zeichenkette, nicht
        // NULL.** *Das hat am 2026-08-29 eine Zeile mit `kind = ''` hinterlassen, und damit **zwei
        // Darstellungen desselben Zustands**: `fromStorage()` liest beide als «niemand hat etwas
        // gesagt», aber `WHERE kind IS NOT NULL` findet nur eine. `$wpdb->update()` schreibt für
        // `null` ein echtes NULL — gemessen, nicht erinnert.*
        //
        // ⚠️ *Der Fassungswächter bleibt derselbe: er steht im `WHERE` und ist damit der Schreibvorgang
        // selbst statt eines Lesens mit Hoffnung (P4c).*
        // ⚠️ *Vor dem Schreiben in den Schatten ([D-536](../../../docs/NewConcept/90-decision-log.md)).
        // Die Version zählt der Kern hoch, nicht dieser Weg — ein Knoten weiss, in welcher Version er
        // ist.*
        Shadow::keepOne('nodes', $node->id);

        $written = $wpdb->update(
            Schema::table('nodes'),
            [
                'version'        => $node->version,
                'name'           => $node->name,
                'path'           => $node->path,
                'field_type'     => $node->fieldType?->value,
                // ⚠️ *Fährt mit, aus demselben Grund wie `kind`: ein Umbenennen hätte sonst die
                // Klassenangabe gelöscht (TASK-008).*
                'implemented_by' => $node->implementedBy,
            ],
            [
                'id'      => $node->id,
                'version' => $expectedVersion,
            ],
            ['%d', '%s', '%s', '%s', '%s'],
            ['%d', '%d']
        );

        if ($written === 1) {
            return;
        }

        $current = $this->find($node->id);

        if ($current === null) {
            throw NodeNotFound::withId($node->id);
        }

        // MySQL reports 0 changed rows for a write that matched but altered nothing. Only a
        // version that actually moved on is a collision.
        if ($current->version !== $expectedVersion) {
            throw ConcurrentChange::on($node->id, $expectedVersion, $current->version);
        }
    }

    /**
     * ⚠️ *Eine Abfrage für alle Eltern zusammen — `GROUP BY` statt einer Runde je Zeile (`CD-7`).*
     *
     * @param  list<int>              $parentIds
     * @return array<int, list<Node>>
     */
    public function visibleChildrenOf(array $parentIds): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $parentIds))));

        if ($ids === []) {
            return [];
        }

        // ⚠️ *Jede angefragte Id bekommt einen Eintrag, auch die ohne Kinder — sonst müsste jeder
        // Aufrufer denselben `?? []` schreiben, und einer würde ihn vergessen.*
        $kinder = array_fill_keys($ids, []);

        $platzhalter = implode(',', array_fill(0, count($ids), '%d'));

        $rows = Query::rows('sichtbare Kinder lesen', $wpdb->prepare(
            'SELECT r.from_node_id, n.id, n.version, n.name, n.path, n.field_type, n.implemented_by
             FROM ' . Schema::table('relations') . ' r
             INNER JOIN ' . Schema::table('nodes') . ' n ON n.id = r.to_node_id
             WHERE r.kind = %s AND r.hide = 0 AND r.from_node_id IN (' . $platzhalter . ')
             ORDER BY r.from_node_id ASC, r.sort_order ASC, r.id ASC',
            RelationKind::Inheritance->value,
            ...$ids
        ));

        foreach ($rows ?: [] as $row) {
            $kinder[(int) $row['from_node_id']][] = $this->hydrate($row);
        }

        return $kinder;
    }

    public function childrenOf(Node $parent): array
    {
        global $wpdb;

        // ⚠️ **Asked of the edges, not of the path.** The inheritance rows are the tree
        // (D-014); the path is the shortcut derived from them. And order lives on the edge,
        // because it is per parent — the same node under two parents may sit third under one
        // and first under the other. One statement, one join, no walking (`CD-7`).
        $rows = Query::rows('Kinder lesen', $wpdb->prepare(
            'SELECT n.id, n.version, n.name, n.path, n.field_type, n.implemented_by
             FROM ' . Schema::table('relations') . ' r
             INNER JOIN ' . Schema::table('nodes') . ' n ON n.id = r.to_node_id
             WHERE r.from_node_id = %d AND r.kind = %s
             ORDER BY r.sort_order ASC, r.id ASC',
            $parent->id,
            RelationKind::Inheritance->value
        ));

        return array_map($this->hydrate(...), $rows ?: []);
    }



    public function subtreeOf(Node $root): array
    {
        global $wpdb;

        $rows = Query::rows('Teilbaum lesen', $wpdb->prepare(
            'SELECT id, version, name, path, field_type, implemented_by FROM ' . Schema::table('nodes') . '
             WHERE path LIKE %s
             ORDER BY path ASC',
            $wpdb->esc_like($root->path . '.') . '%'
        ));

        return array_map($this->hydrate(...), $rows ?: []);
    }
    public function moveSubtree(string $oldPath, string $newPath): void
    {
        global $wpdb;

        // One statement for the whole subtree. Done node by node this would be N+1, which the
        // code standard forbids outright (`CD-7`).
        //
        // ⚠️ **The counter rides along in the same UPDATE** (D-349). It has to move: `save()`
        // writes name and path together, so without it a stale form could rename a node and
        // write its old path back, silently undoing somebody else's move. Five hundred
        // descendants cost no extra statement for it.
        // ⚠️ *Auch eine Massenänderung hebt auf ([D-536](../../../docs/NewConcept/90-decision-log.md)).
        // **Sie zählt `version` selbst hoch** — also muss der alte Stand vorher hinüber, sonst fehlt
        // genau die Version, auf die ein Zurückspringen zielt.*
        Shadow::keep('nodes', 'path LIKE %s', [$wpdb->esc_like($oldPath . '.') . '%']);

        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . Schema::table('nodes') . '
                 SET path = CONCAT(%s, SUBSTRING(path, %d)), version = version + 1
                 WHERE path LIKE %s',
                $newPath,
                strlen($oldPath) + 1,
                $wpdb->esc_like($oldPath . '.') . '%'
            )
        );
    }

    public function purgeSubtree(Node $node): void
    {
        global $wpdb;

        $nodes     = Schema::table('nodes');
        $relations = Schema::table('relations');
        $under     = $wpdb->esc_like($node->path . '.') . '%';

        // ⚠️ **Erst in den Schatten, dann weg** ([D-536](../../../docs/NewConcept/90-decision-log.md)).
        // *Der Eigentümer: «auch wenn es gelöscht ist, nur mit Löschkennzeichen versehen». **Hier ist
        // es endgültig für die lebende Tabelle und nicht endgültig für die Geschichte** — und der
        // Anlass steht in den Daten: gemessen am 2026-08-30 gab es **8 Datensätze, deren Knoten es
        // nicht mehr gab**, und niemand konnte mehr sagen, was sie bedeuteten.
        Shadow::keep(
            'relations',
            "id IN (SELECT x.id FROM {$relations} x
                    INNER JOIN {$nodes} n ON n.id = x.to_node_id OR n.id = x.from_node_id
                    WHERE n.id = %d OR n.path LIKE %s)",
            [$node->id, $under],
            true
        );

        Shadow::keep('nodes', 'id = %d OR path LIKE %s', [$node->id, $under], true);

        // The edges go first, because a relation row whose node is gone is the dangling
        // reference the whole two-stage deletion exists to avoid. Both are one statement.
        $wpdb->query($wpdb->prepare(
            "DELETE r FROM {$relations} r
             INNER JOIN {$nodes} n ON n.id = r.to_node_id OR n.id = r.from_node_id
             WHERE n.id = %d OR n.path LIKE %s",
            $node->id,
            $under
        ));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$nodes} WHERE id = %d OR path LIKE %s",
            $node->id,
            $under
        ));
    }

    /**
     * Zwei Abfragen für beliebig viele Knoten und beliebige Tiefe.
     *
     * ⚠️ *Erst die angefragten Knoten mit ihrem Pfad, dann **alle** darin genannten Vorfahren, die
     * überhaupt eine Sorte tragen — eine Abfrage, nicht eine je Stufe (`CD-7`). Danach läuft die
     * Auflösung in PHP über den Pfad von hinten nach vorn.*
     */
    public function resolvedFieldTypes(array $ids): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $slots = implode(',', array_fill(0, count($ids), '%d'));

        $rows = Query::rows('Feldsorten am Pfad lesen', $wpdb->prepare(
            'SELECT id, path, field_type FROM ' . Schema::table('nodes') . " WHERE id IN ($slots)",
            ...$ids
        )) ?: [];

        // Jede Id, die in irgendeinem Pfad vorkommt — das sind die Kandidaten für den Lauf.
        $entlang = [];

        foreach ($rows as $row) {
            foreach (explode('.', (string) $row['path']) as $stufe) {
                $entlang[(int) $stufe] = true;
            }
        }

        $sorten = [];

        if ($entlang !== []) {
            $wo    = array_keys($entlang);
            $slots = implode(',', array_fill(0, count($wo), '%d'));

            foreach (
                Query::rows('Feldsorten entlang des Pfades lesen', $wpdb->prepare(
                    'SELECT id, field_type FROM ' . Schema::table('nodes')
                        . " WHERE id IN ($slots) AND field_type IS NOT NULL",
                    ...$wo
                )) ?: [] as $row
            ) {
                $sorte = FieldType::fromStorage((string) $row['field_type']);

                if ($sorte !== null) {
                    $sorten[(int) $row['id']] = $sorte;
                }
            }
        }

        $aufgeloest = [];

        foreach ($rows as $row) {
            $stufen = array_reverse(explode('.', (string) $row['path']));
            $gefunden = FieldType::standard();

            foreach ($stufen as $stufe) {
                if (isset($sorten[(int) $stufe])) {
                    $gefunden = $sorten[(int) $stufe];

                    break;
                }
            }

            $aufgeloest[(int) $row['id']] = $gefunden;
        }

        // ⚠️ *Eine Id, die es nicht gibt, bekommt trotzdem eine Antwort — der Aufrufer soll nicht
        // zwischen «kein Knoten» und «keine Sorte» unterscheiden müssen, um eine Zeile einzuordnen.*
        foreach ($ids as $id) {
            $aufgeloest[$id] ??= FieldType::standard();
        }

        return $aufgeloest;
    }

    public function settingsRecordIdsOf(array $nodeIds): array
    {
        global $wpdb;

        $nodeIds = array_values(array_unique(array_filter(array_map(intval(...), $nodeIds))));

        if ($nodeIds === []) {
            return [];
        }

        $slots = implode(',', array_fill(0, count($nodeIds), '%d'));

        $rows = Query::rows('Einstellungsdatensaetze der Knoten lesen', $wpdb->prepare(
            'SELECT id, settings_record_id FROM ' . Schema::table('nodes')
                . " WHERE id IN ($slots) AND settings_record_id IS NOT NULL",
            ...$nodeIds
        ));

        $aus = [];

        foreach ($rows as $row) {
            $satz = (int) $row['settings_record_id'];

            if ($satz !== 0) {
                $aus[(int) $row['id']] = $satz;
            }
        }

        return $aus;
    }

    public function rememberSettingsRecord(int $nodeId, int $recordId): void
    {
        global $wpdb;

        // ⚠️ *`NULL` und nicht `0`: die Spalte soll «hier nichts gesagt» sagen können, und eine
        // Null wäre eine Satz-Id, die es nie gibt — zwei Bedeutungen in einem Wert.
        // `prepare()` kann kein `NULL` einsetzen, darum zwei Anweisungen statt eines Platzhalters.*
        if ($recordId === 0) {
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . Schema::table('nodes') . ' SET settings_record_id = NULL WHERE id = %d',
                $nodeId
            ));

            return;
        }

        $wpdb->query($wpdb->prepare(
            'UPDATE ' . Schema::table('nodes') . ' SET settings_record_id = %d WHERE id = %d',
            $recordId,
            $nodeId
        ));
    }

    public function byImplementations(array $classNames): array
    {
        global $wpdb;

        $classNames = array_values(array_unique(array_filter(
            array_map(static fn (string $n): string => trim($n), $classNames),
            static fn (string $n): bool => $n !== ''
        )));

        if ($classNames === []) {
            return [];
        }

        $slots = implode(',', array_fill(0, count($classNames), '%s'));

        // ⚠️ *`ORDER BY id` — die kleinste Id gewinnt, wenn zwei Zeilen dieselbe Klasse nennen. Das
        // ist ein Befund und keine Auswahl; der Wächter meldet ihn, dieser Weg bleibt nur stabil.*
        $rows = Query::rows('Knoten nach Klasse lesen', $wpdb->prepare(
            'SELECT id, version, name, path, field_type, implemented_by FROM ' . Schema::table('nodes')
                . " WHERE implemented_by IN ($slots) ORDER BY id",
            ...$classNames
        ));

        $aus = [];

        foreach ($rows ?: [] as $row) {
            $klasse = (string) $row['implemented_by'];

            $aus[$klasse] ??= $this->hydrate($row);
        }

        return $aus;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): Node
    {
        return Node::fromStorage(
            (int) $row['id'],
            (int) $row['version'],
            (string) $row['name'],
            (string) $row['path'],
            // ⚠️ *`??` und nicht `[...]`: eine Abfrage, die nur `id` und `path` holt, hat die Spalte
            // nicht dabei, und das ist kein Fehler — sie soll dann «niemand hat etwas gesagt» heissen.*
            FieldType::fromStorage(isset($row['field_type']) ? (string) $row['field_type'] : null),
            // ⚠️ *Dieselbe Vorsicht, und dazu: eine leere Zeichenkette ist `null`. **Zwei
            // Schreibweisen für «nichts» sind der Fehler, den `kind` schon einmal hatte.***
            isset($row['implemented_by']) && (string) $row['implemented_by'] !== ''
                ? (string) $row['implemented_by']
                : null,
        );
    }
}
