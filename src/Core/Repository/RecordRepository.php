<?php declare(strict_types=1);

namespace Taxmod\Core\Repository;

use Taxmod\Core\Model\NodeRecord;
use Taxmod\Core\Model\EdgeRecord;
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

    /** @return list<NodeRecord> Every record entered against one model node. */
    public function ofNode(int $nodeId): array;

    /**
     * Die Datensätze **mehrerer** Knoten — in einer Abfrage.
     *
     * ⚠️ **Gebraucht seit [D-602](../../../docs/NewConcept/90-decision-log.md).** *Die Auflösungskette
     * einer Einstellung geht über die **Vorfahren**, und ein Knoten hat so viele Vorfahren, wie er
     * tief steht. Mit {@see self::ofNode()} je Stufe wäre die Zahl der Abfragen die Tiefe des Baums —
     * genau das N+1, das `CD-7` verbietet und `package7-check.php` misst.*
     *
     * @param  list<int>                    $nodeIds
     * @return array<int, list<NodeRecord>> Je angefragte Id genau ein Eintrag, notfalls leer.
     */
    public function ofNodes(array $nodeIds): array;

    /** @return list<EdgeRecord> Everything one record holds, in one statement. */
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
     * @return array<int, list<EdgeRecord>> Je angefragte Id genau ein Eintrag, notfalls leer.
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
     * @return array<int, EdgeRecord> Satz-Id => die Wertzeile, die ihn hält; fehlt sie, ist er eigenständig.
     */
    public function holdersOf(array $recordIds): array;
    public function putValue(EdgeRecord $value): void;

    public function forgetValue(int $recordId, string $path, string $locale): void;

    /** Genau eine Wertzeile — mehrere Werte eines Feldes teilen sich einen Pfad ([D-530](../../../docs/NewConcept/90-decision-log.md)). */
    public function forgetValueById(int $id): void;

    /**
     * Einen ganzen Datensatz entfernen, mitsamt seinen Werten.
     *
     * WICHTIG: Gebraucht, seit die Wahl eines Renderers einen Datensatz anlegt (D-583). Waehlt
     * jemand einen anderen, sind die Felder des alten sinnlos -- sie gehoeren einem Knoten, der
     * hier nicht mehr steht. Stehen zu lassen hiesse, Waisen zu erzeugen.
     */
    public function forgetRecord(int $id): void;

    /**
     * Records whose value at one edge equals this one, wherever in the record it sits.
     *
     * ⚠️ **This is what `edge_id` is for** (D-134) — the question *which parts are 4k7* asked
     * once, over an index, rather than by unpacking every record. A range asks the same way with
     * a comparison instead of an equality; the shape is the same and only the operator differs.
     *
     * @return list<int> record ids
     */
    public function findByEdgeValue(int $edgeId, TypedValue $value): array;

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
