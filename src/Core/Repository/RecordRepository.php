<?php declare(strict_types=1);

namespace Taxmod\Core\Repository;

use Taxmod\Core\Model\NodeRecord;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\TypedValue;

/**
 * Storage for the data half.
 *
 * ⚠️ **Its own identity space** (D-164), so `AUTO_INCREMENT` serves it. Model tables run in the
 * hundreds and data tables in the millions; keeping the two apart is also what stops anybody
 * merging them later.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
interface RecordRepository
{
    public function add(NodeRecord $record): int;

    public function find(int $id): ?NodeRecord;

    /**
     * Mehrere Sätze in einer Abfrage — die Zusammenfassung (D-753) braucht zu jedem verwiesenen Satz seinen Knoten.
     *
     * @param  list<int>              $ids
     * @return array<int, NodeRecord> Satz-Id => Satz; ohne Eintrag, wo keiner steht.
     */
    public function byIds(array $ids): array;

    /** @return list<NodeRecord> Every record entered against one model node. */
    public function ofNode(int $nodeId): array;

    /**
     * Der Satz dieser Verwendungsstelle, falls einer angelegt ist.
     *
     * ⚠️ *Seit [D-667](../../../docs/NewConcept/90-decision-log.md): ein Satz **ohne** Kante gehört
     * dem Knoten, einer **mit** gehört dieser Stelle. Vorher stand diese Zugehörigkeit als Text im
     * Pfad einer Wertzeile.*
     */
    public function ofRelation(int $relationId): ?NodeRecord;

    /**
     * Der Satz eines **Knotens** zu einer Kante, die er geerbt hat — die Adresse `Knoten × Kante` (D-697).
     *
     * ⚠️ *{@see self::ofRelation()} findet den Satz des **Besitzers** der Kante. Ein Erbe, der ein geerbtes
     * Auswahlfeld verengt («welche Kinder sind hier erlaubt»), schreibt an dieselbe Kante, aber unter
     * seiner eigenen Nummer — [D-667](../../../docs/NewConcept/90-decision-log.md) hat den Satz dafür
     * vorgesehen, `node_id` und `relation_id` nebeneinander.*
     */
    public function ofRelationAt(int $nodeId, int $relationId): ?NodeRecord;

    /**
     * Die Sätze an vielen Adressen `Knoten × Kante` auf einmal (`CD-7`) — die ganze Kette eines Knotens
     * zu allen seinen Zeilen, wie die Anordnung sie liest ([D-698](../../../docs/NewConcept/90-decision-log.md)).
     *
     * @param  list<int>                             $nodeIds
     * @param  list<int>                             $relationIds
     * @return array<int, array<int, NodeRecord>> Knoten-Id => Kanten-Id => Satz; ohne Eintrag, wo keiner steht.
     */
    public function ofRelationsAt(array $nodeIds, array $relationIds): array;

    /**
     * Dieselbe Frage für viele Stellen auf einmal (`CD-7`).
     *
     * ⚠️ *Ein Formular fragt alle seine Felder nacheinander. Einzeln gefragt kostet das eine Abfrage
     * je Feld — genau das misst `einstellungen-check.php`, und genau das hat es gemeldet, als es diese
     * Methode noch nicht gab: **18 Abfragen für 7 Felder**.*
     *
     * @param  list<int>              $relationIds
     * @return array<int, NodeRecord> Kanten-Id => ihr Satz; ohne Eintrag, wo keiner steht.
     */
    public function ofRelations(array $relationIds): array;

    /**
     * Die Datensätze **mehrerer** Knoten — in einer Abfrage.
     *
     * ⚠️ **Gebraucht seit [D-602](../../../docs/NewConcept/90-decision-log.md).** *Die Auflösungskette
     * einer Einstellung geht über die **Vorfahren**, und ein Knoten hat so viele Vorfahren, wie er
     * tief steht. Mit {@see self::ofNode()} je Stufe wäre die Zahl der Abfragen die Tiefe des Baums —
     * genau das N+1, das `CD-7` verbietet und `einstellungen-check.php` misst.*
     *
     * @param  list<int>                    $nodeIds
     * @return array<int, list<NodeRecord>> Je angefragte Id genau ein Eintrag, notfalls leer.
     */
    public function ofNodes(array $nodeIds): array;

    /** @return list<RelationRecord> Everything one record holds, in one statement. */
    public function valuesOf(int $recordId): array;

    /**
     * Die Kanten-Datensätze **mehrerer** Knoten-Datensätze — in einer Abfrage.
     *
     * ⚠️ **Damit die Teile einer Einstellung nicht je Teil eine Abfrage kosten** (`CD-7`,
     * [D-159](../../../docs/NewConcept/90-decision-log.md): *«both loaded before it starts»*). *Eine
     * Einstellung mit `1..*` kann mehrere Teile haben — [D-548](../../../docs/NewConcept/90-decision-log.md),
     * für das Farbschema — und jeder ist ein eigener Knoten-Datensatz. Mit `valuesOf()` je Teil wäre die
     * Zahl der Abfragen die Zahl der Zeilen, und genau das verbietet die Regel.*
     *
     * @param  list<int>                    $recordIds
     * @return array<int, list<RelationRecord>> Je angefragte Id genau ein Eintrag, notfalls leer.
     */
    public function valuesOfMany(array $recordIds): array;

    /**
     * Wer diese Datensätze **hält** — je Satz die Wertzeile, die auf ihn zeigt, oder kein Eintrag.
     *
     * ⚠️ **Ein Teil ist ein Datensatz wie jeder andere, und genau das macht ihn ununterscheidbar.**
     * *Gemessen am 2026-08-31 an `Einheitenwert`: 23 Datensätze, **einer davon** ein Teil des Satzes von
     * `__uv Resistor` über die Kante `resistance`. Er stand zwischen den anderen, ohne dass etwas ihn
     * unterschied — und er ist kein Datensatz dieses Knotens im gewöhnlichen Sinn, sondern ein Stück
     * eines fremden. Der Eigentümer wollte die Spalte: «zu welchem Knoten/Kante es gehört, würde ich auch
     * noch vorne dran schreiben».*
     *
     * ⚠️ *In **einer** Abfrage für alle (`CD-7`), weil die Frage je Zeile eines Blocks gestellt wird.*
     *
     * @param  list<int> $recordIds
     * @return array<int, RelationRecord> Satz-Id => die Wertzeile, die ihn hält; fehlt sie, ist er eigenständig.
     */
    public function holdersOf(array $recordIds): array;
    /**
     * Eine Wertzeile schreiben — und **sagen, welche Version dabei entstanden ist**.
     *
     * ⚠️ **Der Rückgabewert ist neu und er ist der Grund, warum Wertänderungen überhaupt melden
     * können** ([D-634](../../../docs/NewConcept/90-decision-log.md)): *die Version wird im Speicher
     * gezählt, weil `RelationRecord` sie nicht trägt. Ohne diese Antwort wüsste der Kern die Nummer nicht,
     * die er ins Änderungsbuch schreiben muss — und genau daran lag es, dass er es nicht tat.*
     *
     * @return int Die Version der geschriebenen Zeile; bei einer neuen Zeile die erste.
     */
    public function putValue(RelationRecord $value): int;

    /**
     * Jede Wertzeile dieses Feldes in diesem Datensatz.
     *
     * ⚠️ *Adressiert über die **Kante** und nicht mehr über einen Pfad (Fassung 39, TASK-002,
     * [D-667](../../../docs/NewConcept/90-decision-log.md)) — die Spalte sagte ohnehin nichts, was
     * `relation_id` nicht schon sagte.*
     *
     * @return int|null Die Version der entfernten Zeile, oder null, wenn keine da war.
     */
    public function forgetValue(int $recordId, int $relationId, string $locale): ?int;

    /**
     * Genau eine Wertzeile — mehrere Werte eines Feldes teilen sich eine Kante ([D-530](../../../docs/NewConcept/90-decision-log.md)).
     *
     * @return int|null Die Version der entfernten Zeile, oder null, wenn keine da war.
     */
    public function forgetValueById(int $id): ?int;

    /**
     * Einen ganzen Datensatz entfernen, mitsamt seinen Werten.
     *
     * WICHTIG: Gebraucht, seit die Wahl eines Renderers einen Datensatz anlegt (D-583). Waehlt
     * jemand einen anderen, sind die Felder des alten sinnlos -- sie gehoeren einem Knoten, der
     * hier nicht mehr steht. Stehen zu lassen hiesse, Waisen zu erzeugen.
     *
     * @return int|null Die Version des entfernten Datensatzes, oder null, wenn es ihn nicht gab.
     */
    public function forgetRecord(int $id): ?int;

    /**
     * Die **Art** eines bestehenden Datensatzes umstellen — `default`, `user` oder `example`.
     *
     * ⚠️ **Sein Wort:** *«default / user / example muss einstellbar sein.»* *Gewaehlt wurde sie
     * bisher nur beim Anlegen ([D-651](../../../docs/NewConcept/90-decision-log.md),
     * [D-653](../../../docs/NewConcept/90-decision-log.md)) — danach stand sie als Spalte da und
     * liess sich nicht mehr anfassen.*
     *
     * ⚠️ **Es ist keine Kleinigkeit an einer Anzeige, sondern eine Wirkung**
     * ([D-654](../../../docs/NewConcept/90-decision-log.md)): *ein `default` ist eine **Vorbelegung**
     * und greift in jede kuenftige Eingabe ein, ein `example` wird nur gezeigt. Umstellen aendert
     * also, was mit kuenftigen Datensaetzen geschieht* — darum zaehlt die Zeile ihre Version hoch
     * und geht mit ihrem alten Zustand in den Schatten, wie jede andere Aenderung.
     *
     * @return int|null Die neue Version, oder null, wenn es den Datensatz nicht gibt.
     */
    public function retypeRecord(int $id, RecordType $kind): ?int;

    /**
     * Einen Satz an einen anderen Knoten hängen — seine Werte bleiben, denn sie hängen an Kanten, nicht am Knoten
     * ([D-756](../../../docs/NewConcept/90-decision-log.md)).
     *
     * @return int|null Die neue Version; `null`, wenn es den Satz nicht gibt.
     */
    public function moveRecord(int $id, int $nodeId): ?int;

    /**
     * Records whose value at one relation equals this one, wherever in the record it sits.
     *
     * ⚠️ **This is what `relation_id` is for** (D-134) — the question *which parts are 4k7* asked
     * once, over an index, rather than by unpacking every record. A range asks the same way with
     * a comparison instead of an equality; the shape is the same and only the operator differs.
     *
     * @return list<int> record ids
     */
    public function findByRelationValue(int $relationId, TypedValue $value): array;

    /**
     * Everything these nodes hold as data — the records and their values.
     *
     * ⚠️ **Das ist die Durchsetzung von [C102](../../../docs/NewConcept/10-domain-core.md), und die
     * fehlte.** Der Eigentümer: *«solange noch eine Referenz da ist, kann ein Knoten nicht endgültig
     * gelöscht werden. Somit wäre ein Record ohne Knoten undenkbar. Und wenn man ihn löschen will und
     * das Risiko eingeht, dann müssen die Daten **mitgelöscht** werden.»*
     *
     * ⚠️ *Sein Grund ist der bessere Teil: **«sonst weiss man ja auch gar nicht, wie dieser Record
     * interpretiert werden soll. Wir haben ein einziges Datum, einen Text oder eine Zahl — was soll
     * ich denn damit machen? Das weiss keiner mehr.»** Ein Wert ohne sein Feld ist keine Altlast,
     * sondern eine Zahl ohne Bedeutung.*
     *
     * ⚠️ **Der Changelog geht ausdrücklich nicht mit** ([D-065](../../../docs/NewConcept/90-decision-log.md)):
     * die Geschichte überlebt die Sache. *Was hier verschwindet, sind **Daten**, nicht die Nachricht,
     * dass es sie gab.*
     *
     * @param  list<int> $nodeIds
     * @return array{records: int, values: int} Was ging, damit eine Oberfläche es sagen kann.
     */
    public function forgetNodes(array $nodeIds): array;
}
