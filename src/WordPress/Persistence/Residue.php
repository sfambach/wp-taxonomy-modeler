<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\IdentitySpace;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Node;
use Taxmod\WordPress\Admin\SettingsScreen;
use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\LabelRepository;
use Taxmod\Core\Repository\RecordRepository;

/**
 * What deliberate non-tidying left behind — **measured in one place, removed in the same place**.
 *
 * [D-247](../../../docs/NewConcept/90-decision-log.md) names three sources:
 * [D-156](../../../docs/NewConcept/90-decision-log.md)'s **orphaned overrides**,
 * [D-159](../../../docs/NewConcept/90-decision-log.md)'s **values whose relation is gone**, and **nodes
 * with no connections any more**. *Each of the three was decided as «leave it alone rather than tidy
 * it silently», which is right at the moment of the change and leaves residue over years.*
 *
 * ⚠️ **Eine vierte kam 2026-08-28 vom Eigentümer dazu** — {@see self::recordsWithoutNode()}: *ein
 * Datensatz, dessen Knoten es nicht mehr gibt.* **Sie ist nicht «auch noch so ein Fall», sondern
 * die einzige, die einen ausdrücklich verbotenen Zustand misst:** *«ein Record ohne Knoten darf es
 * nicht geben.» Ein Verbot beseitigt keinen Rückstand, und ohne diese Messung wäre es ein Satz
 * ohne Prüfung.*
 *
 * ```mermaid
 * flowchart LR
 *   Q["this · the queries"] --> C["the Cleanup screen"]
 *   Q --> S["scripts/dev/orphans-*.php"]
 * ```
 *
 * ⚠️ **It exists because the same queries were already written twice on the command line.**
 * *`orphans-check.php` measures and `orphans-clean.php` removes; a screen would have been a third
 * copy, and `CLAUDE.md` forbids exactly that — «one place owns each piece of state». **All three
 * callers ask this class now**, so a correction to the query reaches every one of them.*
 *
 * ⚠️ **At the boundary and not in the core**, because every one of these questions is a question
 * about **rows** — `NOT EXISTS`, across tables the core has no interfaces for (`CD-1`). *A residue row
 * names an owner that no longer exists, so there is nothing for a repository of objects to return.*
 *
 * ⚠️ **The installation identity is not an orphan and every naive query says it is.** *It is an
 * identity with no node and no relation behind it
 * ([D-079](../../../docs/NewConcept/90-decision-log.md)) and its rows are the declared defaults for
 * the switches ([D-404](../../../docs/NewConcept/90-decision-log.md)). A sweep that took it would
 * take the answer to «what does `persistent` mean when nobody said» with it.*
 *
 * ⚠️ **Every removal re-measures before it acts.** *An id arrives from a form, and «this owner is
 * gone» is a fact about the database and not about the form. So each `forget…` asks again and removes
 * nothing when the answer changed in the meantime — which is also what makes a double-click harmless.*
 *
 * @see docs/NewConcept/20-interaction.md
 */
final class Residue
{
    /**
     * What `owner_kind` says when neither `node` nor `relation` is true any more.
     *
     * ⚠️ **A fourth kind, and it is an assumption rather than a decision.** *{@see \Taxmod\Core\Service\Settings}
     * already needed a third — `installation` — and its comment says why the honest answer matters:
     * «the first version of this line called that a `relation`, which was simply a lie.» **An orphaned
     * override names an owner that is neither**, and the only two things known about it are its id and
     * that nothing answers for it. Calling it a node would be the same lie one step further on.*
     */
    private const KIND_GONE = 'gone';

    public function __construct(
        private readonly FrameworkNodes $framework,
        private readonly LabelRepository $labels,
        private readonly Changelog $changelog,
        // ⚠️ *Optional, damit die vorhandene Verdrahtung weiterläuft — ohne ihn meldet die
        // vierte Quelle nichts, statt zu behaupten, es gebe nichts.*
        private readonly ?RecordRepository $records = null,
    ) {
    }

    /*
     * Hier stand `orphanedSettings()` — verwaiste Zeilen der `settings`-Tabelle
     * ([D-156](../../../docs/NewConcept/90-decision-log.md)). **Die Tabelle ist mit
     * [D-579](../../../docs/NewConcept/90-decision-log.md) gestrichen; eine Quelle, die es nicht
     * mehr gibt, kann keinen Rest mehr hinterlassen.** *Die drei anderen Quellen — Labels, Knoten
     * ohne Verbindung, Datensaetze ohne Knoten — bleiben unberuehrt.*
     */

    /**
     * The same for labels.
     *
     * ⚠️ *[D-247](../../../docs/NewConcept/90-decision-log.md) says «settings that broke because
     * something was deleted» and names labels nowhere. They are residue of the same shape, and
     * `orphans-check.php` has measured them since row 28 — so the query lives here, and **whether the
     * screen offers them is a decision the owner has not made**.*
     *
     * ⚠️ **Seit TASK-019 zeigt der Verweis in die Gegenrichtung** ([D-580](../../../docs/NewConcept/90-decision-log.md)),
     * *also lautet die Frage anders: verwaist ist eine Beschriftung, auf die **niemand mehr zeigt** —
     * weder ein Knoten noch eine Kante. Der Schlüssel ist damit die Nummer der Beschriftung.*
     *
     * @return array<int,int> Beschriftungsnummer ⇒ wie viele Textzeilen sie noch hält
     */
    public function orphanedLabels(): array
    {
        $rows = $this->rows(
            'SELECT l.id AS owner, COUNT(t.id) AS rows_held FROM ' . Schema::table('labels') . ' l
             LEFT JOIN ' . Schema::table('label_texts') . ' t ON t.label_id = l.id
             WHERE NOT EXISTS (SELECT 1 FROM ' . Schema::table('nodes') . ' n WHERE n.label_id = l.id)
               AND NOT EXISTS (SELECT 1 FROM ' . Schema::table('relations') . ' r WHERE r.label_id = l.id)
               AND NOT EXISTS (SELECT 1 FROM ' . Schema::table('relations_history') . ' h WHERE h.label_id = l.id AND h.parked_by_group_id IS NOT NULL AND h.version = (SELECT MAX(version) FROM ' . Schema::table('relations_history') . ' h2 WHERE h2.id = h.id))
             GROUP BY l.id
             ORDER BY l.id ASC'
        );

        return $this->countsByOwner($rows);
    }

    /**
     * Record values whose relation is gone — [D-159](../../../docs/NewConcept/90-decision-log.md).
     *
     * ⚠️ **D-159 is the decision *not* to touch these while drawing**: *«those are simply not drawn,
     * and **not touched**: they stay in `relation_records` … because removing them is a migration
     * ([D-061](../../../docs/NewConcept/90-decision-log.md)) and migrating is not the job of
     * drawing.»* *This class is not drawing. It is the surface D-247 asks for, where the same rows are
     * removed **deliberately** — which is the only place that sentence leaves open.*
     *
     * @return array<int,int> relation id ⇒ how many values still name it
     */
    public function valuesWithoutRelation(): array
    {
        $rows = $this->rows(
            'SELECT v.relation_id AS owner, COUNT(*) AS rows_held FROM ' . Schema::table('relation_records') . ' v
             WHERE NOT EXISTS (SELECT 1 FROM ' . Schema::table('relations') . ' r WHERE r.id = v.relation_id)
             GROUP BY v.relation_id
             ORDER BY v.relation_id ASC'
        );

        return $this->countsByOwner($rows);
    }

    /**
     * Nodes that hang on nothing at all — the third of [D-247](../../../docs/NewConcept/90-decision-log.md)'s sources.
     *
     * ⚠️ **No relation in either direction, and both directions matter.** *A node with no **parent** relation
     * is not necessarily residue — the root has none by construction and is the top of everything. A
     * node with no relation **touching** it is in no tree, holds no attribute and is pointed at by
     * nothing: it is what [D-123](../../../docs/NewConcept/90-decision-log.md)'s purge leaves when an
     * relation went and the node did not.*
     *
     * ⚠️ *Measured 2026-08-28 on the real model: **0**. That is the answer a repair surface should
     * usually give, and it is why the empty case had to read as good news rather than as an empty
     * table.*
     *
     * @return list<Node>
     */
    public function nodesWithoutConnections(): array
    {
        global $wpdb;

        // ⚠️ **Die Baumhälfte der Frage steht seit TASK-018 in einer Spalte**
        // ([D-581](../../../docs/NewConcept/90-decision-log.md)). *Ohne diese beiden Zeilen zählte der
        // Lauf **101 lebende Knoten als Rückstand** — gemessen unmittelbar nach der Wanderung —, weil
        // ihre Vererbungskante fort ist und die Frage nur noch die Kantentabelle befragte. **Ein
        // Aufräumschirm, der 101 gesunde Knoten zum Wegwerfen anbietet, ist schlimmer als keiner.***
        //
        // ⚠️ *`parent_node_id IS NULL` **und** niemand hängt an mir: die Wurzel hat keinen Vater und
        // ist trotzdem kein Rückstand — sie trägt Kinder. Genau diese Unterscheidung stand vorher in
        // «no relation in either direction».*
        // ⚠️ *Der Name kommt seit TASK-019 aus den Beschriftungen ([D-580](../../../docs/NewConcept/90-decision-log.md)),
        // in der Standardsprache — `nodes.name` gibt es nicht mehr.*
        $rows = $this->rows($wpdb->prepare(
            // ⚠️ *Der Weg ist seit Fassung 35 keine Spalte mehr (TASK-001), und hier braucht es
            // dafür keinen Abstieg: **gefragt sind ausschliesslich Knoten ohne Vater und ohne
            // Kinder**, und deren Weg ist ihre eigene Nummer — die Wurzel ihres eigenen Astes.*
            "SELECT n.id, n.version, COALESCE(t.text_name, '') AS name, CAST(n.id AS CHAR) AS path
             FROM " . Schema::table('nodes') . ' n
             LEFT JOIN ' . Schema::table('label_texts') . ' t
               ON t.label_id = n.label_id AND t.locale = %s AND t.number = %s
             WHERE n.parent_node_id IS NULL
               AND NOT EXISTS (SELECT 1 FROM ' . Schema::table('nodes') . ' k WHERE k.parent_node_id = n.id)
               AND NOT EXISTS (
                 SELECT 1 FROM ' . Schema::table('relations') . ' r
                 WHERE r.from_node_id = n.id OR r.to_node_id = n.id
             )
             ORDER BY n.id ASC',
            SettingsScreen::neutralLocale(),
            Label::BASE_NUMBER
        ));

        return array_map(
            static fn (object $row): Node => Node::fromStorage(
                (int) $row->id,
                (int) $row->version,
                (string) $row->name,
                (string) $row->path
            ),
            $rows
        );
    }

    /**
     * Eine Beschriftung, auf die nichts mehr zeigt, samt ihren Texten.
     *
     * ⚠️ **Die Frage hat sich mit TASK-019 umgedreht** ([D-580](../../../docs/NewConcept/90-decision-log.md)).
     * *Vorher hiess sie «welche Nummer nennt ein Label als Eigentümer, den es nicht gibt»; jetzt heisst
     * sie «auf welche Beschriftung zeigt weder ein Knoten noch eine Kante». **Die Zahl, die übergeben
     * wird, ist deshalb die der Beschriftung** und nicht mehr die ihres Eigentümers — es gibt keine
     * Spalte mehr, in der der stünde.*
     */
    public function forgetOrphanedLabels(int $labelId): int
    {
        global $wpdb;

        if (! array_key_exists($labelId, $this->orphanedLabels())) {
            return 0;
        }

        // ⚠️ *Vor dem Löschen gelesen — danach wäre die Version nicht mehr feststellbar. Seit Fassung
        // 31 hat eine Beschriftung eine ([D-634](../../../docs/NewConcept/90-decision-log.md)).*
        $version = $this->hoechsteVersion('labels', 'id = %d', [$labelId]);

        $gone = (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('label_texts') . ' WHERE label_id = %d',
            $labelId
        ));

        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('labels') . ' WHERE id = %d', $labelId));

        $this->record($labelId, self::KIND_GONE, 'labels removed', $gone, $version);

        return $gone;
    }

    /** Remove the values of an relation that no longer exists, and say how many went. */
    public function forgetValuesOfRelation(int $relationId): int
    {
        global $wpdb;

        if (! array_key_exists($relationId, $this->valuesWithoutRelation())) {
            return 0;
        }

        // ⚠️ *Vor dem Löschen gelesen — danach wäre die Version nicht mehr feststellbar.*
        $version = $this->hoechsteVersion('relation_records', 'relation_id = %d', [$relationId]);

        $gone = (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . Schema::table('relation_records') . ' WHERE relation_id = %d',
            $relationId
        ));

        $this->refuseBrokenQuery('relation_records wegräumen');

        // ⚠️ *`relation` and not {@see self::KIND_GONE}: the id came out of `relation_records.relation_id`, so
        // what it **was** is known even though the row it named is not there any more.*
        $this->record($relationId, 'relation', 'values removed', $gone, $version);

        return $gone;
    }

    /**
     * Remove a node that hangs on nothing, with what belonged to it.
     *
     * ⚠️ **Its ids and its changelog stay** ([D-339](../../../docs/NewConcept/90-decision-log.md),
     * [D-065](../../../docs/NewConcept/90-decision-log.md)) — the same promise the trash's «clear»
     * makes, and for the same reason: an id is never handed out twice and the history still says what
     * was there.
     *
     * ⚠️ *Settings and labels go first. They hang off the node, and a row whose owner is gone is the
     * very residue this screen exists to remove — creating more of it while removing some would be a
     * repair that produces work.*
     *
     * @return array{labels: int}|null Null when this id is not a disconnected node —
     *         **which is not the same as a node that owned nothing.** *Both would be two zeroes, and a
     *         page that reported «removed 0 and 0» for a refusal would be reporting an act that never
     *         happened.*
     */
    public function purgeNodeWithoutConnections(int $id): ?array
    {
        global $wpdb;

        $standing = array_filter(
            $this->nodesWithoutConnections(),
            static fn (Node $node): bool => $node->id === $id
        );

        if ($standing === []) {
            return null;
        }

        $gone = [
            // ⚠️ *Ein Knoten wird entfernt, also gehen die Beschriftungen **des Knotens** — nicht die
            // einer gleichnummerigen Kante (Fassung 31, `INF-035`).*
            'labels' => $this->labels->forgetOwners([$id], IdentitySpace::Node),
        ];

        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('nodes') . ' WHERE id = %d', $id));

        $this->refuseBrokenQuery('Knoten entfernen');

        $this->changelog->record(
            $id,
            'node',
            'purged',
            'no connections',
            sprintf('%d labels', $gone['labels']),
            // ⚠️ *Die Version der Knotenzeile, die hier verschwindet — sie steht in
            // {@see self::nodesWithoutConnections()} und wandert mit derselben Nummer in den
            // Schatten.*
            $standing[array_key_first($standing)]->version
        );

        return $gone;
    }

    /**
     * Datensätze, deren Knoten es nicht mehr gibt — die **vierte** Quelle.
     *
     * ⚠️ **Der Eigentümer hat sie benannt und zugleich verboten** (2026-08-28): *«ein Record ohne
     * Knoten wäre undenkbar … sonst weiss man ja auch gar nicht, wie dieser Record interpretiert
     * werden soll. Wir haben ein einziges Datum, einen Text oder eine Zahl — was soll ich denn damit
     * machen?»* **Ein Verbot beseitigt keinen Rückstand**, und darum steht die Messung hier.
     *
     * ⚠️ *Über die Anwendung entsteht der Fall seit
     * [D-485](../../../docs/NewConcept/90-decision-log.md) nicht mehr neu — `clearTrash()` nimmt die
     * Datensätze eines Knotens mit. Was bleibt, ist das, was vorher liegen blieb.*
     *
     * ⚠️ **Gruppiert nach dem verschwundenen Knoten und nicht nach dem Datensatz**, wie die drei
     * anderen Quellen nach ihrem Eigentümer gruppieren — *und weil das Entfernen ohnehin
     * {@see RecordRepository::forgetNodes()} ist, dieselbe Methode, die `clearTrash()` benutzt. Eine
     * zweite Löschung daneben wäre die dritte Kopie derselben Regel.*
     *
     * @return array<int,array{records:int,values:int}> Knoten-Id ⇒ was noch an ihr hängt
     */
    public function recordsWithoutNode(): array
    {
        $rows = $this->rows(
            'SELECT r.node_id AS owner,
                    COUNT(DISTINCT r.id) AS records_held,
                    COUNT(v.node_record_id)   AS values_held
               FROM ' . Schema::table('node_records') . ' r
               LEFT JOIN ' . Schema::table('relation_records') . ' v ON v.node_record_id = r.id
              WHERE NOT EXISTS (SELECT 1 FROM ' . Schema::table('nodes') . ' n WHERE n.id = r.node_id)
           GROUP BY r.node_id
           ORDER BY r.node_id ASC'
        );

        $held = [];

        foreach ($rows as $row) {
            $held[(int) $row->owner] = [
                'records' => (int) $row->records_held,
                'values'  => (int) $row->values_held,
            ];
        }

        return $held;
    }

    /**
     * Die Datensätze eines verschwundenen Knotens entfernen, und sagen wie viel ging.
     *
     * ⚠️ **Der Eigentümer wollte zwei Wege — «entweder Daten löschen oder Knoten wiederherstellen».
     * Gebaut ist einer, und der andere ist nicht vergessen, sondern unentschieden**
     * ([OQ-128](../../../docs/NewConcept/91-open-questions.md)): *ein **geparkter** Knoten steht noch
     * in `nodes`, seine Datensätze sind also gar kein Rückstand. Wer hier auftaucht, ist **endgültig
     * weg** — zurückzuholen wäre er nur aus dem Changelog, und ob das geht, hat niemand entschieden.*
     *
     * @return array{records:int,values:int}|null Null, wenn dieser Knoten kein solcher Fall ist —
     *         **was nicht dasselbe ist wie «er hatte nichts»**, genau wie bei
     *         {@see self::purgeNodeWithoutConnections()}.
     */
    public function forgetRecordsOfGoneNode(int $nodeId): ?array
    {
        if ($this->records === null || ! array_key_exists($nodeId, $this->recordsWithoutNode())) {
            return null;
        }

        // ⚠️ *Vor dem Entfernen gelesen, sonst gäbe es die Zeilen nicht mehr, deren Version gemeint ist.*
        $version = $this->hoechsteVersion('node_records', 'node_id = %d', [$nodeId]);

        $gone = $this->records->forgetNodes([$nodeId]);

        // ⚠️ *`node` und nicht {@see self::KIND_GONE}: die Id kam aus `records.node_id`, es ist also
        // bekannt, **was** sie war — nur die Zeile, die sie nannte, gibt es nicht mehr.*
        $this->changelog->record(
            $nodeId,
            'node',
            'records removed',
            sprintf('%d records, %d values', $gone['records'], $gone['values']),
            null,
            $version
        );

        return $gone;
    }
    /*
     * Hier stand `ownersWithoutOwner()` — «welche Nummer nennt eine Zeile als Eigentuemer, den es
     * nicht gibt».
     *
     * **Sie faellt mit `labels.owner_id`** (TASK-019, D-580): der Verweis zeigt jetzt vom Knoten auf
     * die Beschriftung, also lautet die Frage umgekehrt und steht in {@see self::orphanedLabels()}.
     * *Sie war zuletzt die einzige Benutzerin dieser Methode.*
     */

    /**
     * @param list<object> $rows
     *
     * @return array<int,int>
     */
    private function countsByOwner(array $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->owner] = (int) $row->rows_held;
        }

        return $counts;
    }

    /**
     * One statement, and it never returns an empty result for a broken query.
     *
     * ⚠️ **`$wpdb` answers a broken query with an empty result**, and «no residue» is exactly what
     * this screen most wants to say — *so a typo in a `NOT EXISTS` would read as good news. That
     * mistake was reported to the owner as a fact about his model on 2026-08-26; it is checked after
     * **every** statement here, not once at the end.*
     *
     * @return list<object>
     */
    private function rows(string $sql): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($sql);

        $this->refuseBrokenQuery('Rückstand messen');

        return $rows ?: [];
    }

    private function refuseBrokenQuery(string $doing): void
    {
        global $wpdb;

        if ($wpdb->last_error !== '') {
            throw new \RuntimeException($doing . ': ' . $wpdb->last_error);
        }
    }

    /**
     * One line of history per removal.
     *
     * ⚠️ *[D-081](../../../docs/NewConcept/90-decision-log.md) wants every object to have at least one
     * entry, and [D-065](../../../docs/NewConcept/90-decision-log.md) lets the changelog outlive what
     * it refers to — so removing residue is recorded **against the owner that is already gone**, which
     * is the only place the act can be described.*
     */
    private function record(int $ownerId, string $kind, string $what, int $gone, ?int $version): void
    {
        $this->changelog->record(
            $ownerId,
            $kind,
            $what,
            sprintf('%d rows', $gone),
            null,
            $version
        );
    }

    /**
     * Die höchste Version, die in dieser Tabelle unter dieser Bedingung steht — oder `null`.
     *
     * ⚠️ *Vor dem Löschen zu lesen ist der ganze Zweck: danach gibt es die Zeilen nicht mehr, und die
     * Version, die diese Änderung erzeugt hat ([D-634](../../../docs/NewConcept/90-decision-log.md)),
     * wäre nicht mehr feststellbar. **Die Zeile steht dann im Schatten, und dort trägt sie genau
     * diese Nummer** — das Aufheben zählt nicht hoch.*
     *
     * @param list<int|string> $args
     */
    private function hoechsteVersion(string $table, string $where, array $args): ?int
    {
        global $wpdb;

        $wert = Query::value('höchste Version vor dem Entfernen lesen', $wpdb->prepare(
            'SELECT MAX(version) FROM ' . Schema::table($table) . ' WHERE ' . $where,
            ...$args
        ));

        return $wert === null ? null : (int) $wert;
    }

    /**
     * Benutzersätze ohne Wert **und ohne Journal** — die fünfte Quelle (TASK-077).
     *
     * ⚠️ **Gemessen am 2026-09-09: 111 von 113 Benutzersätzen trugen keine einzige Wertzeile, 104
     * davon an einem Knoten, alle mit `created_at` genau Mitternacht des 2026-08-30 — und das
     * Journal kannte keinen von ihnen.** *Kein Akt hat sie angelegt; ein Import oder ein Umbenennen
     * hat sie hinterlassen.*
     *
     * ⚠️ **«Ohne Journal» ist die Bedingung, die einen echten leeren Satz eines Benutzers ausnimmt:**
     * *der hat eine Anlegezeile. So entscheidet die Geschichte und keine Zahl und kein Datum.*
     *
     * @return array<int,int> Knoten-Id ⇒ wie viele solcher Sätze an ihm hängen
     */
    public function emptyUserRecordsWithoutHistory(): array
    {
        return $this->countsByOwner($this->rows(
            'SELECT r.node_id AS owner, COUNT(*) AS rows_held
               FROM ' . Schema::table('node_records') . " r
              WHERE r.record_type = 'user'
                AND EXISTS (SELECT 1 FROM " . Schema::table('nodes') . ' n WHERE n.id = r.node_id)
                AND NOT EXISTS (SELECT 1 FROM ' . Schema::table('relation_records') . ' v WHERE v.node_record_id = r.id)
                AND NOT EXISTS (SELECT 1 FROM ' . Schema::table('changelog') . " c WHERE c.owner_kind = 'record' AND c.owner_id = r.id)
           GROUP BY r.node_id
           ORDER BY r.node_id ASC"
        ));
    }

    /**
     * Die leeren, journallosen Benutzersätze eines Knotens entfernen — in den Schatten, mit Eintrag.
     *
     * @return int|null Wie viele gingen; null, wenn an diesem Knoten kein solcher Fall liegt.
     */
    public function forgetEmptyUserRecordsOf(int $nodeId): ?int
    {
        global $wpdb;

        if ($this->records === null || ! array_key_exists($nodeId, $this->emptyUserRecordsWithoutHistory())) {
            return null;
        }

        // ⚠️ *Die Knoten-Id ist eine `(int)`-Umwandlung, nichts aus der Eingabe wird interpoliert (`CD-6`).*
        $ids = array_map(static fn (object $r): int => (int) $r->owner, $this->rows(
            'SELECT r.id AS owner FROM ' . Schema::table('node_records') . ' r
              WHERE r.node_id = ' . (int) $nodeId . " AND r.record_type = 'user'
                AND NOT EXISTS (SELECT 1 FROM " . Schema::table('relation_records') . ' v WHERE v.node_record_id = r.id)
                AND NOT EXISTS (SELECT 1 FROM ' . Schema::table('changelog') . " c WHERE c.owner_kind = 'record' AND c.owner_id = r.id)"
        ));

        $gone = 0;

        foreach ($ids as $id) {
            // ⚠️ *Über den Speicher, nicht mit rohem SQL: so wandert die Zeile in den Schatten und
            // bleibt umkehrbar, wie jeder andere entfernte Satz.*
            $version = $this->records->forgetRecord($id);

            if ($version !== null) {
                ++$gone;
                $this->record($id, 'record', 'empty record without history removed', 1, $version);
            }
        }

        return $gone;
    }
}
