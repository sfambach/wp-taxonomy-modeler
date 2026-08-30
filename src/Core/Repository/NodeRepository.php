<?php declare(strict_types=1);

namespace Taxmod\Core\Repository;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeKind;

/**
 * Storage for nodes, stated as the core needs it rather than as a database offers it.
 *
 * ⚠️ **`moveSubtree` exists so that `CD-7` can be kept.** Reparenting rewrites the `path` of
 * every descendant; done node by node that is N+1, and the concept forbids it. The interface
 * therefore asks for the whole subtree in one call and lets the boundary do it in one statement.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
interface NodeRepository
{
    /** @throws \Taxmod\Core\Exception\NodeNotFound */
    public function byId(int $id): Node;

    public function find(int $id): ?Node;

    /**
     * Several nodes at once, so a caller with a list does not query in a loop (`CD-7`).
     *
     * @param  list<int>        $ids
     * @return array<int, Node> Keyed by id. Ids that no longer exist are simply absent.
     */
    public function byIds(array $ids): array;

    public function add(Node $node): void;

    /**
     * @param int $expectedVersion The version the caller read. Guards against a concurrent
     *                             change (P4c).
     *
     * @throws \Taxmod\Core\Exception\ConcurrentChange
     * @throws \Taxmod\Core\Exception\NodeNotFound
     */
    public function save(Node $node, int $expectedVersion): void;

    /**
     * Direct children, in `position` order once relations carry one.
     *
     * @return list<Node>
     */
    public function childrenOf(Node $parent): array;

    /**
     * Rewrite the paths of everything below a node that has just moved, in one statement.
     *
     * @param string $oldPath The subtree's path before the move.
     * @param string $newPath The same subtree's path after it.
     */
    public function moveSubtree(string $oldPath, string $newPath): void;

    /**
     * Everything below a node, in one statement, at any depth.
     *
     * ⚠️ **This is what the materialised path is for.** Reading a tree level by level is the
     * recursive query per level `CD-7` forbids outright; one `LIKE` on the path answers it
     * whole, and the caller assembles the shape in memory.
     *
     * @return list<Node>
     */
    public function subtreeOf(Node $root): array;

    /** Remove a node and everything under it, for good. The irreversible half of D-123. */
    public function purgeSubtree(Node $node): void;

    /**
     * Die aufgelöste Sorte je Knoten — die eigene, sonst die des nächsten Vorfahren, der eine hat.
     *
     * ⚠️ **Der Vorfahrenlauf, den [D-518](../../../docs/NewConcept/90-decision-log.md) verlangt und
     * den [D-516](../../../docs/NewConcept/90-decision-log.md) für den **Typ** schon gemessen hat.**
     * *`null` in der Spalte heisst «frag meine Vorfahren», nicht «unbekannt» — **eine Spalte plus
     * Vorfahrenlauf gibt Vererbung ohne die Settings-Maschinerie**, und das ist der Grund, dass diese
     * Angabe eine Spalte sein darf, wo `multiplicity` eine Setting-Zeile bleiben musste.*
     *
     * ⚠️ **In einer festen Zahl von Abfragen, nicht einer je Ebene** (`CD-7`). *Der Pfad ist
     * materialisiert, also stehen alle Vorfahren-Ids schon da; es braucht keinen Aufstieg mit einer
     * Abfrage je Stufe.*
     *
     * @param  list<int>              $ids
     * @return array<int, NodeKind>   Je angefragte Id genau ein Eintrag — nie `null`, weil
     *                                {@see NodeKind::standard()} das Ende des Laufs beantwortet.
     */
    public function resolvedKinds(array $ids): array;

    /**
     * Wie viele Kinder ein Knoten zu zeigen hat.
     *
     * ⚠️ **Das ist die Frage, an der [D-540](../../../docs/NewConcept/90-decision-log.md) hängt.** *Seine
     * Regel: ein Feld ist eine **Auswahl**, wenn sein Ziel sichtbare, unmarkierte Kinder hat — sonst
     * eine Eingabe. Nicht der Zweig entscheidet das und nicht der Name des Ziels, sondern was unter dem
     * Ziel steht.*
     *
     * ⚠️ **Gebündelt, weil sonst jede Zeile einer Feldtabelle eine Abfrage kostet** (`CD-7`). *Eine
     * Tabelle mit dreissig Feldern ist dreissig Ziele, und die Antwort wird für alle in einem Zug
     * gebraucht.*
     *
     * ⚠️ *Versteckt zählt nicht mit: eine ausgeblendete Kante wird nicht gezeichnet, also steht sie
     * auch nicht zur Wahl. **Eine Auswahl mit null Möglichkeiten wäre schlechter als ein Textfeld.***
     *
     * ⚠️ **Die Kinder selbst und nicht ihre Zahl**, weil derselbe Lauf beides braucht: *ob* es eine
     * Auswahl ist, und *woraus* sie besteht. *Zwei Leser für eine Frage wären zwei Gelegenheiten,
     * verschieden zu antworten.*
     *
     * @param  list<int>                $parentIds
     * @return array<int, list<Node>>   Je angefragte Id genau ein Eintrag, notfalls leer, in der
     *                                  Reihenfolge des Modells.
     */
    public function visibleChildrenOf(array $parentIds): array;
}
