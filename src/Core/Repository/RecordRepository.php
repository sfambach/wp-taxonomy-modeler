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

    /** @return list<EdgeRecord> Everything one record holds, in one statement. */
    public function valuesOf(int $recordId): array;

    public function putValue(EdgeRecord $value): void;

    public function forgetValue(int $recordId, string $path, string $locale): void;

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
