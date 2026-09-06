<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\NodeRecord;
use Taxmod\Core\Model\ReferenceSpace;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Repository\RecordRepository;

/**
 * The data half, in its own two tables and its own id space (D-164).
 *
 * ⚠️ **`AUTO_INCREMENT` serves records** precisely because they do **not** share the model's
 * space. The model's had to be a table of its own so that nodes and relations could draw from one
 * number; here there is no such ambiguity, and a hand-built allocator on the hottest write path
 * in the system would be paid for nothing.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class WpdbRecordRepository implements RecordRepository
{
    public function add(NodeRecord $record): int
    {
        global $wpdb;

        $wpdb->insert(
            Schema::table('node_records'),
            [
                'node_id'      => $record->nodeId,
                'node_version' => $record->nodeVersion,
                'created_at'   => $record->createdAt,
                // ⚠️ *Die Spalte ist `NOT NULL` mit Vorgabe, also gibt es hier keine Null-Falle —
                // anders als bei `nodes.kind`, wo `$wpdb->prepare('%s', null)` eine leere
                // Zeichenkette schrieb ([D-519](../../../docs/NewConcept/90-decision-log.md)).*
                'record_type'  => $record->recordType->value,
                // ⚠️ *`0` heisst «gehört dem Knoten» — der Normalfall und jeder Satz vor Fassung 37
                // ([D-667](../../../docs/NewConcept/90-decision-log.md)).*
                'relation_id'  => $record->relationId,
            ],
            ['%d', '%d', '%s', '%s', '%d']
        );

        return (int) $wpdb->insert_id;
    }

    public function find(int $id): ?NodeRecord
    {
        global $wpdb;

        $row = Query::row('Datensatz lesen', $wpdb->prepare(
            'SELECT id, node_id, node_version, created_at, record_type, relation_id FROM ' . Schema::table('node_records')
                . ' WHERE id = %d',
            $id
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * ⚠️ **`AND relation_id = 0`, seit Fassung 37** ([D-667](../../../docs/NewConcept/90-decision-log.md)):
     * *die Sätze **des Knotens**, nicht die seiner Verwendungsstellen. Ein Satz einer Kante trägt in
     * `node_id` weiter ihren **Halter** — sonst wüsste niemand, wohin er gehört —, und ohne diese
     * Bedingung läse die Kette des Knotens die Überschreibung **einer** Stelle als seine eigene
     * Angabe. **Gemessen, als sie fehlte: `renderer-choice-check` meldete an sechs Knoten
     * «gespeichert `table`, gezeichnet `plain`».*** Zu ihnen führt {@see self::ofRelation()}.
     */
    public function ofNode(int $nodeId): array
    {
        global $wpdb;

        $rows = Query::rows('Datensaetze des Knotens lesen', $wpdb->prepare(
            'SELECT id, node_id, node_version, created_at, record_type, relation_id FROM ' . Schema::table('node_records') . '
             WHERE node_id = %d AND relation_id = 0 ORDER BY id ASC',
            $nodeId
        ));

        return array_map($this->hydrate(...), $rows ?: []);
    }

    /**
     * ⚠️ *Eine Abfrage für alle Knoten, sonst kostet jede Stufe der Vorfahrenkette eine eigene
     * (`CD-7`, [D-602](../../../docs/NewConcept/90-decision-log.md)).*
     *
     * @param  list<int>                    $nodeIds
     * @return array<int, list<NodeRecord>>
     */
    public function ofNodes(array $nodeIds): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $nodeIds))));

        if ($ids === []) {
            return [];
        }

        // ⚠️ *Jede angefragte Id bekommt einen Eintrag, auch die ohne Sätze — sonst müsste jeder
        // Aufrufer denselben `?? []` schreiben, und einer würde ihn vergessen.*
        $nachKnoten  = array_fill_keys($ids, []);
        $platzhalter = implode(',', array_fill(0, count($ids), '%d'));

        $rows = Query::rows('Datensaetze der Knoten lesen', $wpdb->prepare(
            'SELECT id, node_id, node_version, created_at, record_type, relation_id FROM ' . Schema::table('node_records') . '
             WHERE node_id IN (' . $platzhalter . ') AND relation_id = 0 ORDER BY id ASC',
            ...$ids
        ));

        foreach ($rows ?: [] as $row) {
            $nachKnoten[(int) $row['node_id']][] = $this->hydrate($row);
        }

        return $nachKnoten;
    }

    /**
     * ⚠️ *Eine Abfrage für alle Sätze, sonst kostet jeder Teil einer Einstellung eine eigene (`CD-7`).
     * Dieselbe Reihenfolge wie {@see self::valuesOf()} — `position`, bei Gleichstand die Id.*
     *
     * @param  list<int>                    $recordIds
     * @return array<int, list<RelationRecord>>
     */
    public function valuesOfMany(array $recordIds): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $recordIds))));

        if ($ids === []) {
            return [];
        }

        // ⚠️ *Jede angefragte Id bekommt einen Eintrag, auch die ohne Werte — sonst müsste jeder
        // Aufrufer denselben `?? []` schreiben, und einer würde ihn vergessen.*
        $nachSatz    = array_fill_keys($ids, []);
        $platzhalter = implode(',', array_fill(0, count($ids), '%d'));

        $rows = Query::rows('Wertzeilen mehrerer Datensaetze lesen', $wpdb->prepare(
            'SELECT id, node_record_id, relation_id, locale, position, value_int, value_decimal, value_text, value_date, value_ref, value_ref_kind
             FROM ' . Schema::table('relation_records') . '
             WHERE node_record_id IN (' . $platzhalter . ') ORDER BY node_record_id ASC, position ASC, id ASC',
            ...$ids
        ));

        foreach ($rows ?: [] as $r) {
            $nachSatz[(int) $r['node_record_id']][] = new RelationRecord(
                (int) $r['node_record_id'],
                (int) $r['relation_id'],
                (string) $r['locale'],
                TypedValue::fromStorage(
                    $r['value_int'] === null ? null : (int) $r['value_int'],
                    StoredDecimal::read($r['value_decimal']),
                    $r['value_text'] === null ? null : (string) $r['value_text'],
                    $r['value_date'] === null ? null : (string) $r['value_date'],
                    $r['value_ref'] === null ? null : (int) $r['value_ref'],
                    ReferenceSpace::tryFrom((string) ($r['value_ref_kind'] ?? '')),
                ),
                (int) $r['id'],
                (int) $r['position'],
            );
        }

        return $nachSatz;
    }

    public function valuesOf(int $recordId): array
    {
        global $wpdb;

        $rows = Query::rows('Wertzeilen des Datensatzes lesen', $wpdb->prepare(
            // ⚠️ *Nach `position` geordnet und **bei Gleichstand nach der Id**
            // ([D-530](../../../docs/NewConcept/90-decision-log.md)): so hat auch ein Feld, dem
            // niemand eine Reihenfolge gegeben hat, eine stabile — die des Eintragens.*
            'SELECT id, node_record_id, relation_id, locale, position, value_int, value_decimal, value_text, value_date, value_ref, value_ref_kind
             FROM ' . Schema::table('relation_records') . '
             WHERE node_record_id = %d ORDER BY position ASC, id ASC',
            $recordId
        ));

        return array_map(
            static fn (array $r): RelationRecord => new RelationRecord(
                (int) $r['node_record_id'],
                (int) $r['relation_id'],
                (string) $r['locale'],
                TypedValue::fromStorage(
                    $r['value_int'] === null ? null : (int) $r['value_int'],
                    // ⚠️ The padding `decimal(30,10)` adds on read is taken off here and nowhere else.
                    StoredDecimal::read($r['value_decimal']),
                    $r['value_text'] === null ? null : (string) $r['value_text'],
                    $r['value_date'] === null ? null : (string) $r['value_date'],
                    $r['value_ref'] === null ? null : (int) $r['value_ref'],
                    ReferenceSpace::tryFrom((string) ($r['value_ref_kind'] ?? '')),
                ),
                (int) $r['id'],
                (int) $r['position'],
            ),
            $rows ?: []
        );
    }

    /**
     * Einen Wert schreiben — **einfügen, wenn er keine Id hat, sonst genau diese Zeile ändern**.
     *
     * ⚠️ **Hier stand `$wpdb->replace()` auf dem Schlüssel `(node_record_id, path, locale)`, und das war
     * der ganze Grund für eine erfundene laufende Nummer** ([D-530](../../../docs/NewConcept/90-decision-log.md)).
     * *Drei Werte eines Feldes teilen sich eine Kante und damit einen Pfad — `replace()` behielt einen
     * davon. **Der Eigentümer sah es sofort:** «warum führen wir jetzt eine neue Zahl ein, wo wir doch
     * die Id des Records haben?»*
     *
     * ⚠️ *`insert()` und `update()` statt `replace()`: `replace` löscht und schreibt neu, **die Zeile
     * bekäme also bei jedem Speichern eine neue Id** — und an der Id hängt jetzt, welcher Wert das ist.*
     */
    /**
     * Eine Wertzeile aus einer Datenbankzeile — **an einer Stelle**.
     *
     * ⚠️ *Diese fünfzehn Zeilen standen dreimal da, einmal je Abfrage. Die vierte Abschrift wäre die
     * vierte Gelegenheit, eine Spalte zu vergessen.*
     *
     * @param array<string, mixed> $r
     */
    private function valueFromRow(array $r): RelationRecord
    {
        return new RelationRecord(
            (int) $r['node_record_id'],
            (int) $r['relation_id'],
            (string) $r['locale'],
            TypedValue::fromStorage(
                $r['value_int'] === null ? null : (int) $r['value_int'],
                StoredDecimal::read($r['value_decimal']),
                $r['value_text'] === null ? null : (string) $r['value_text'],
                $r['value_date'] === null ? null : (string) $r['value_date'],
                $r['value_ref'] === null ? null : (int) $r['value_ref'],
                ReferenceSpace::tryFrom((string) ($r['value_ref_kind'] ?? '')),
            ),
            (int) $r['id'],
            (int) $r['position'],
        );
    }
    public function holdersOf(array $recordIds): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $recordIds))));

        if ($ids === []) {
            return [];
        }

        $platzhalter = implode(',', array_fill(0, count($ids), '%d'));

        // ⚠️ *`value_ref` ist der Sprung **zwischen** Datensätzen. Ein Verweis auf einen Knoten steht in
        // derselben Spalte — **seit TASK-005 sagt `value_ref_kind`, welcher von beiden gemeint ist**,
        // und die Abfrage fragt nur noch die Datensatzverweise. Vorher hätte ein Knoten mit der Nummer
        // eines Datensatzes hier mitgeliefert; heute nicht mehr.*
        $rows = Query::rows('Halter der Datensaetze lesen', $wpdb->prepare(
            'SELECT id, node_record_id, relation_id, locale, position, value_int, value_decimal, value_text, value_date, value_ref, value_ref_kind
             FROM ' . Schema::table('relation_records') . '
             WHERE value_ref_kind = \'record\' AND value_ref IN (' . $platzhalter . ')
             ORDER BY value_ref ASC, id ASC',
            ...$ids
        ));

        // ⚠️ **«Abfrage kaputt» heisst nicht «nichts gefunden»** — und «keine Teile» ist hier eine
        // Aussage, die auf dem Schirm landet. *Die Unterscheidung stand einmal nur an dieser einen
        // Stelle; sie steht jetzt in {@see Query} und gilt für jede Lesung.*
        $aus = [];

        foreach ($rows as $r) {
            $ziel = (int) $r['value_ref'];

            if (isset($aus[$ziel])) {
                continue;
            }

            $aus[$ziel] = $this->valueFromRow($r);
        }

        return $aus;
    }
    public function putValue(RelationRecord $value): int
    {
        global $wpdb;

        $spalten = [
            'node_record_id'     => $value->recordId,
            'relation_id'       => $value->relationId,
            'locale'        => $value->locale,
            'position'      => $value->position,
            'value_int'     => $value->value->int,
            'value_decimal' => $value->value->decimal,
            'value_text'    => $value->value->text,
            'value_date'    => $value->value->date,
            'value_ref'     => $value->value->reference,
            // ⚠️ *Der Raum wird **mitgeschrieben**, nicht abgeleitet: sobald jede Tabelle ihren eigenen
            // Id-Raum hat (TASK-004), sagt die Nummer allein nicht mehr, auf welche Tabelle sie zeigt.*
            'value_ref_kind' => $value->value->referenceSpace?->value,
        ];

        $formate = ['%d', '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%d', '%s'];

        if ($value->id === null) {
            $wpdb->insert(Schema::table('relation_records'), $spalten, $formate);

            // ⚠️ *Die Spalte hat die Vorgabe `1`, also ist das die Version der neuen Zeile — gelesen
            // aus dem Schema und nicht geraten ({@see Schema}).*
            return 1;
        }

        // ⚠️ **Zuerst die alte Zeile in den Schatten, dann schreiben** ([D-536](../../../docs/NewConcept/90-decision-log.md)).
        // *Umgekehrt hielte der Schatten zweimal den neuen Wert.*
        Shadow::keepOne('relation_records', $value->id);

        // ⚠️ *Die Version wird hier gezählt und nicht im Kern: **`RelationRecord` trägt sie nicht**, weil
        // eine Version eine Aussage über die Zeile im Speicher ist und nicht über den Wert. Ohne das
        // Hochzählen träfe jedes Aufheben denselben Schlüssel `(id, 1)` und der Schatten hielte nur
        // den ersten Zustand.*
        $spalten['version'] = 1 + (int) Query::value('Version der Wertzeile lesen', $wpdb->prepare(
            'SELECT version FROM ' . Schema::table('relation_records') . ' WHERE id = %d',
            $value->id
        ));

        $wpdb->update(
            Schema::table('relation_records'),
            $spalten,
            ['id' => $value->id],
            [...$formate, '%d'],
            ['%d']
        );

        return $spalten['version'];
    }

    /**
     * Genau eine Wertzeile entfernen.
     *
     * ⚠️ *Über die Id, weil mehrere Werte eines Feldes denselben Pfad tragen
     * ([D-530](../../../docs/NewConcept/90-decision-log.md)) — über den Pfad träfe es alle.*
     */
    public function forgetValueById(int $id): ?int
    {
        global $wpdb;

        // ⚠️ *Vor dem Löschen gelesen: die Version, die diese Änderung erzeugt hat, ist die, mit der
        // die Zeile in den Schatten geht — das Aufheben zählt sie nicht hoch.*
        $version = $this->versionOfValues('id = %d', [$id]);

        Shadow::keepOne('relation_records', $id, true);

        $wpdb->delete(Schema::table('relation_records'), ['id' => $id], ['%d']);

        return $version;
    }

    /**
     * Die höchste Version, die unter dieser Bedingung in `relation_records` steht — oder `null`.
     *
     * @param list<int|string> $args
     */
    private function versionOfValues(string $where, array $args): ?int
    {
        global $wpdb;

        $wert = Query::value('Version der Wertzeile vor dem Entfernen lesen', $wpdb->prepare(
            'SELECT MAX(version) FROM ' . Schema::table('relation_records') . ' WHERE ' . $where,
            ...$args
        ));

        return $wert === null ? null : (int) $wert;
    }

    /**
     * Einen ganzen Datensatz entfernen -- erst seine Werte, dann ihn selbst.
     *
     * WICHTIG: In dieser Reihenfolge, sonst zeigen die Werte auf nichts mehr und die
     * Schattenzeile haette keinen Datensatz, zu dem sie gehoert.
     */
    public function forgetRecord(int $id): ?int
    {
        global $wpdb;

        $version = Query::value('Version des Datensatzes vor dem Entfernen lesen', $wpdb->prepare(
            'SELECT version FROM ' . Schema::table('node_records') . ' WHERE id = %d',
            $id
        ));

        Shadow::keep('relation_records', 'node_record_id = %d', [$id], true);
        $wpdb->delete(Schema::table('relation_records'), ['node_record_id' => $id], ['%d']);

        Shadow::keepOne('node_records', $id, true);
        $wpdb->delete(Schema::table('node_records'), ['id' => $id], ['%d']);

        return $version === null ? null : (int) $version;
    }

    /**
     * Die Art eines bestehenden Datensatzes umstellen — mit Version und Schattenzeile.
     *
     * ⚠️ **Erst in den Schatten, dann schreiben** ([D-536](../../../docs/NewConcept/90-decision-log.md)),
     * *dieselbe Reihenfolge wie bei einer Wertzeile: umgekehrt hielte der Schatten zweimal die neue
     * Art, und der Zustand davor waere nirgends.*
     */
    public function retypeRecord(int $id, RecordType $kind): ?int
    {
        global $wpdb;

        $version = Query::value('Version des Datensatzes vor dem Umstellen lesen', $wpdb->prepare(
            'SELECT version FROM ' . Schema::table('node_records') . ' WHERE id = %d',
            $id
        ));

        if ($version === null) {
            return null;
        }

        Shadow::keepOne('node_records', $id);

        $neu = 1 + (int) $version;

        $wpdb->update(
            Schema::table('node_records'),
            ['record_type' => $kind->value, 'version' => $neu],
            ['id' => $id],
            ['%s', '%d'],
            ['%d']
        );

        return $neu;
    }

    public function forgetValue(int $recordId, int $relationId, string $locale): ?int
    {
        global $wpdb;

        $version = $this->versionOfValues(
            'node_record_id = %d AND relation_id = %d AND locale = %s',
            [$recordId, $relationId, $locale]
        );

        // ⚠️ **Sie verschwindet aus der lebenden Tabelle und bleibt im Schatten**
        // ([D-536](../../../docs/NewConcept/90-decision-log.md), [D-537](../../../docs/NewConcept/90-decision-log.md)).
        // *Der Eigentümer: «auch wenn es gelöscht ist, nur mit Löschkennzeichen versehen». **In der
        // lebenden Tabelle gibt es kein Kennzeichen** — ein Wert ist da oder er ist nicht da.*
        Shadow::keep('relation_records', 'node_record_id = %d AND relation_id = %d AND locale = %s', [$recordId, $relationId, $locale], true);

        // ⚠️ The row disappears, and the attribute is **unanswered** — which is a third state
        // beside a value and an explicit nothing, and collapsing it would lose it for good.
        $wpdb->delete(
            Schema::table('relation_records'),
            ['node_record_id' => $recordId, 'relation_id' => $relationId, 'locale' => $locale],
            ['%d', '%d', '%s']
        );

        return $version;
    }

    public function findByRelationValue(int $relationId, TypedValue $value): array
    {
        global $wpdb;

        // ⚠️ This is the query D-134 was designed for: the relation is indexed, so *which parts are
        // 4k7* is one lookup rather than a walk through every record.
        [$column, $bound] = match (true) {
            $value->int !== null       => ['value_int', $value->int],
            $value->decimal !== null   => ['value_decimal', $value->decimal],
            $value->date !== null      => ['value_date', $value->date],
            $value->reference !== null => ['value_ref', $value->reference],
            default                    => ['value_text', (string) $value->text],
        };

        $ids = Query::column('Datensaetze zu einem Kantenwert lesen', $wpdb->prepare(
            'SELECT DISTINCT node_record_id FROM ' . Schema::table('relation_records') . "
             WHERE relation_id = %d AND {$column} = %s",
            $relationId,
            $bound
        ));

        return array_map(intval(...), $ids ?: []);
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): NodeRecord
    {
        return new NodeRecord(
            (int) $row['id'],
            (int) $row['node_id'],
            (int) $row['node_version'],
            (string) $row['created_at'],
            // ⚠️ *`?? null` und nicht `$row['record_type']` allein: `hydrate()` bekommt auch Zeilen aus
            // Abfragen, die die Spalte nicht auswaehlen — ein fehlender Schluessel waere eine Warnung
            // und danach stillschweigend die Vorgabe. **So ist es dieselbe Vorgabe, aber ausgesprochen.**
            // *
            RecordType::fromStorage(isset($row['record_type']) ? (string) $row['record_type'] : null),
            // ⚠️ *Aus demselben Grund abgesichert wie die Zeile darüber, und mit derselben Vorgabe:
            // «gehört dem Knoten» ([D-667](../../../docs/NewConcept/90-decision-log.md)).*
            (int) ($row['relation_id'] ?? 0),
        );
    }

    /**
     * Der Satz **dieser Verwendungsstelle** — die Einstellungen, die hier und nur hier gelten.
     *
     * ⚠️ **Einer, nicht mehrere** ([D-667](../../../docs/NewConcept/90-decision-log.md)): *eine Kante
     * ist eine Stelle und keine Sammlung. Wo ein Knoten mehrere Sätze hat — Vorgabe, Beispiel,
     * Eingaben —, hat eine Verwendungsstelle genau die Einstellungen, die an ihr gesetzt sind.*
     */
    public function ofRelation(int $relationId): ?NodeRecord
    {
        global $wpdb;

        if ($relationId === 0) {
            return null;
        }

        $row = Query::row('Satz der Verwendungsstelle lesen', $wpdb->prepare(
            'SELECT id, node_id, node_version, created_at, record_type, relation_id FROM '
                . Schema::table('node_records') . ' WHERE relation_id = %d ORDER BY id ASC LIMIT 1',
            $relationId
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @param  list<int>              $relationIds
     * @return array<int, NodeRecord>
     */
    public function ofRelations(array $relationIds): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('intval', $relationIds))));

        if ($ids === []) {
            return [];
        }

        $platzhalter = implode(',', array_fill(0, count($ids), '%d'));

        $rows = Query::rows('Saetze der Verwendungsstellen lesen', $wpdb->prepare(
            'SELECT id, node_id, node_version, created_at, record_type, relation_id FROM '
                . Schema::table('node_records') . '
             WHERE relation_id IN (' . $platzhalter . ') ORDER BY id ASC',
            ...$ids
        ));

        $nachKante = [];

        foreach ($rows ?: [] as $row) {
            $nachKante[(int) $row['relation_id']] ??= $this->hydrate($row);
        }

        return $nachKante;
    }

    /**
     * ⚠️ **Die Werte zuerst, die Records danach** — sonst findet die zweite Anweisung nicht mehr,
     * welche Records es waren. *Derselbe Reihenfolgefehler, den `clearTrash()` schon einmal gemacht
     * hat: die Kanten vor den Knoten zu löschen nahm den Weg mit, auf dem die Knoten gefunden wurden,
     * und 53 blieben liegen.*
     *
     * ⚠️ *`$wpdb->last_error` wird nach **jeder** Anweisung gelesen: eine kaputte Abfrage antwortet mit
     * einem leeren Ergebnis und sieht aus wie «nichts zu löschen».*
     */
    public function forgetNodes(array $nodeIds): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_map(intval(...), $nodeIds)));

        if ($ids === []) {
            return ['records' => 0, 'values' => 0];
        }

        $places  = implode(',', array_fill(0, count($ids), '%d'));
        $records = Schema::table('node_records');
        $values  = Schema::table('relation_records');

        // ⚠️ **Auch die Massenlöschung hebt auf** ([D-535](../../../docs/NewConcept/90-decision-log.md)).
        // *Gemessen am 2026-08-30, bevor es das gab: **8 Datensätze hatten einen Knoten, den es nicht
        // mehr gibt**, und niemand konnte mehr sagen, was sie bedeuteten — genau sein Argument
        // («sonst weiss man ja auch gar nicht, wie dieser Record interpretiert werden soll»).*
        Shadow::keep(
            'relation_records',
            "node_record_id IN (SELECT id FROM {$records} WHERE node_id IN ({$places}))",
            $ids,
            true
        );

        Shadow::keep('node_records', "node_id IN ({$places})", $ids, true);

        $goneValues = (int) $wpdb->query($wpdb->prepare(
            "DELETE v FROM {$values} v
             INNER JOIN {$records} r ON r.id = v.node_record_id
             WHERE r.node_id IN ({$places})",
            ...$ids
        ));

        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Die Werte der Datensätze liessen sich nicht löschen: ' . $wpdb->last_error);
        }

        $goneRecords = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$records} WHERE node_id IN ({$places})",
            ...$ids
        ));

        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Die Datensätze liessen sich nicht löschen: ' . $wpdb->last_error);
        }

        return ['records' => $goneRecords, 'values' => $goneValues];
    }
}
