<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Exception\CannotRestore;
use Taxmod\Core\Exception\ImpossibleMove;
use Taxmod\Core\Exception\NodeIsProtected;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\IdentityAllocator;
use Taxmod\Core\Repository\NodeRepository;
use Taxmod\Core\Repository\RelationRepository;

/**
 * Everything a person can do to the shape of the model: make a node, rename it, move it,
 * reorder it among its siblings, throw it away.
 *
 * ⚠️ **The tree is the inheritance edges; `path` is derived from them** (D-014). Every operation
 * here changes the edge first and rewrites the path afterwards. Writing the path alone would
 * make the derived value the only truth, which is exactly what D-014 forbids.
 *
 * ⚠️ **Deletion is two stages, and only the second is irreversible** (D-123). Parking is an
 * ordinary move — under the trash. The node stays a real node, so nothing that pointed at it
 * dangles and a conflict can be sorted out afterwards in peace instead of in a dialog blocking
 * the delete.
 *
 * ```mermaid
 * flowchart LR
 *   A[in the model] -->|park = move under the trash| B[under the trash]
 *   B -->|restore = move back| A
 *   B -->|purge| C[gone]
 * ```
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class ModelEditor
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly RelationRepository $relations,
        private readonly IdentityAllocator $identities,
        private readonly FrameworkNodes $framework,
        private readonly Changelog $changelog,
    ) {
    }

    public function createNode(string $name, int $parentId): Node
    {
        $parent = $this->nodes->byId($parentId);

        // Two identities, because an edge is a first-class thing that can carry settings and
        // labels of its own (C8) — and both come from the one model space (C11).
        $node = Node::create($this->identities->next(), $name, $parent->path);
        $edge = Relation::inheritance(
            $this->identities->next(),
            $parent->id,
            $node->id,
            $this->relations->nextPositionUnder($parent->id)
        );

        $this->nodes->add($node);
        $this->relations->add($edge);
        $this->changelog->record($node->id, 'node', 'created', null, $this->state($node));

        return $node;
    }

    public function rename(int $id, string $name): Node
    {
        $node    = $this->nodes->byId($id);
        $renamed = $node->renamedTo($name);

        // Same instance means nothing changed, and an unchanged save does not raise the
        // version (D-282) — so there is nothing to write and nothing to log either.
        if ($renamed === $node) {
            return $node;
        }

        $this->nodes->save($renamed, $node->version);
        $this->changelog->record($id, 'node', 'renamed', $this->state($node), $this->state($renamed));

        return $renamed;
    }

    /** Hang a node under a different parent, taking everything below it along. */
    public function move(int $id, int $newParentId): Node
    {
        return $this->reparent($id, $this->nodes->byId($newParentId), 'moved');
    }

    /**
     * Park a node and everything under it.
     *
     * ⚠️ **The old path is written to the changelog and nowhere else.** That is what a restore
     * reads, and it is why the node itself needs no `parked_from` column — one place owns each
     * fact.
     */
    public function moveToTrash(int $id): Node
    {
        return $this->reparent($id, $this->framework->trash(), 'parked');
    }

    /**
     * Park **only** this node; its children are hung on its parent.
     *
     * ⚠️ **Not the harmless half of the choice** ([U4](../../../docs/NewConcept/20-interaction.md)).
     * The tree is inheritance (D-041), so children reattached to the grandparent **lose whatever
     * they inherited from the node being removed**. That is D-155's move reached through a
     * different button, and it is why deleting asks rather than guesses.
     */
    public function moveToTrashPromotingChildren(int $id): Node
    {
        $node = $this->nodes->byId($id);

        if ($this->framework->isProtected($node)) {
            throw NodeIsProtected::named($node->name);
        }

        $edge        = $this->relations->inheritanceEdgeTo($id) ?? throw ImpossibleMove::ofTheRoot();
        $grandparent = $this->nodes->byId($edge->fromId);

        // ⚠️ **One row per promoted child, not one row saying *the children moved*** (D-348).
        // A restore has to know **which** child went **where** to put it back, and *these
        // children moved* is not an answer. Read before the move, written in one statement.
        $promoted = [];

        foreach ($this->relations->childEdgesOf($id) as $childEdge) {
            $child = $this->nodes->find($childEdge->toId);

            if ($child === null) {
                continue;
            }

            $promoted[] = [
                'ownerId'   => $child->id,
                'ownerKind' => 'node',
                'what'      => 'promoted',
                'before'    => $child->path,
                'after'     => $grandparent->path . '.' . $child->id,
            ];
        }

        // Both halves in one statement each: the edges repoint together, and the paths of every
        // descendant are rewritten by dropping this node out of the middle of them. Done child
        // by child, either would be a write per row (`CD-7`).
        $this->relations->reparentChildEdges($id, $grandparent->id, $this->relations->nextPositionUnder($grandparent->id));
        $this->nodes->moveSubtree($node->path, $grandparent->path);

        // The bracket opens here and the parking joins it, so both are one act (D-348).
        $group = $this->changelog->recordMany($promoted);

        if ($promoted === []) {
            $group = null;
        }

        // The node is childless now, so parking it is the ordinary move.
        return $this->reparent($id, $this->framework->trash(), 'parked', $group);
    }

    /** Put a node at a different place among its siblings. Order lives on the edge, not the node. */
    public function reorder(int $id, int $position): void
    {
        $edge = $this->relations->inheritanceEdgeTo($id) ?? throw ImpossibleMove::ofTheRoot();
        $moved = $edge->movedTo(max(0, $position));

        if ($moved === $edge) {
            return;
        }

        $this->relations->save($moved, $edge->version);
        $this->changelog->record($id, 'node', 'reordered', (string) $edge->position, (string) $moved->position);
    }

    /**
     * Put a parked node back where it came from, with everything under it.
     *
     * ⚠️ **This is what makes the trash a trash rather than a graveyard.** *Undo reaches
     * exactly as far as the trash* (D-172), and without a way back the first stage of the
     * two-stage deletion buys nothing.
     *
     * **Where it came from is read out of the changelog and nowhere else** — no `parked_from`
     * column, because one place owns each fact (D-123, D-065).
     */
    public function restore(int $id): RestoreResult
    {
        $node  = $this->nodes->byId($id);
        $trash = $this->framework->trash();

        if (! $node->isDescendantOf($trash)) {
            throw CannotRestore::itWasNeverParked($node->name);
        }

        $was = $this->changelog->pathBeforeLastParking($id);

        if ($was === null) {
            throw CannotRestore::theOldPlaceIsGone($node->name);
        }

        $segments = explode('.', $was);
        array_pop($segments);
        $parent = $segments === [] ? null : $this->nodes->find((int) end($segments));

        if ($parent === null) {
            throw CannotRestore::theOldPlaceIsGone($node->name);
        }

        // Restoring into the trash would look like success and change nothing.
        if ($parent->id === $trash->id || $parent->isDescendantOf($trash)) {
            throw CannotRestore::theOldPlaceIsAlsoParked($node->name, $parent->name);
        }

        // The whole act comes back, not the row (D-347). The bracket is what makes *the whole
        // act* nameable at all (D-348) — without it there is only a list of unrelated lines.
        $act      = $this->changelog->actAround($id, 'parked');
        $restored = $this->reparent($id, $parent, 'restored');

        $back = [];
        $left = [];

        foreach ($act as $row) {
            if ($row['what'] !== 'promoted') {
                continue;
            }

            $child = $this->nodes->find($row['ownerId']);

            // ⚠️ Untouched means *still exactly where the promotion put it*. Anything else is a
            // newer decision by a person, and it wins.
            if ($child === null || $child->path !== $row['after']) {
                if ($child !== null) {
                    $left[] = $child->name;
                }

                continue;
            }

            $this->reparent($child->id, $restored, 'restored');
            $back[] = $child->name;
        }

        return new RestoreResult($restored, $back, $left);
    }

    /** Swap a node with the sibling before it. Does nothing if it is already first. */
    public function moveUp(int $id): void
    {
        $this->swapWithNeighbour($id, -1);
    }

    /** Swap a node with the sibling after it. Does nothing if it is already last. */
    public function moveDown(int $id): void
    {
        $this->swapWithNeighbour($id, 1);
    }

    /** @return list<Node> */
    public function childrenOf(int $parentId): array
    {
        return $this->nodes->childrenOf($this->nodes->byId($parentId));
    }

    /**
     * Exchange two neighbouring edges' positions.
     *
     * ⚠️ **A swap, not a renumbering.** Reordering by rewriting every sibling would be a write
     * per row, which is the loop `CD-7` forbids; a swap is always exactly two, however many
     * siblings there are.
     */
    private function swapWithNeighbour(int $id, int $direction): void
    {
        $edge     = $this->relations->inheritanceEdgeTo($id) ?? throw ImpossibleMove::ofTheRoot();
        $siblings = $this->relations->childEdgesOf($edge->fromId);

        $here = null;

        foreach ($siblings as $index => $sibling) {
            if ($sibling->id === $edge->id) {
                $here = $index;
                break;
            }
        }

        $there = $here + $direction;

        if ($here === null || ! isset($siblings[$there])) {
            return;
        }

        $other = $siblings[$there];

        // Positions may be equal — nothing forbids it, and the list then falls back to id
        // order. Swapping equal numbers would move nothing, so they are forced apart.
        $mine  = $edge->position;
        $yours = $other->position;

        if ($mine === $yours) {
            $mine  = $here;
            $yours = $there;
        }

        $this->relations->save($edge->movedTo($yours), $edge->version);
        $this->relations->save($other->movedTo($mine), $other->version);

        $this->changelog->record($id, 'node', 'reordered', (string) $here, (string) $there);
    }

    /**
     * Change which parent an edge points at, then bring the paths along.
     *
     * ⚠️ **The order is not arbitrary.** The edge is the truth, so it moves first; the paths of
     * the node and everything under it are rewritten from it afterwards, in one statement
     * rather than one per descendant (`CD-7`).
     */
    private function reparent(int $id, Node $newParent, string $verb, ?int $changeGroup = null): Node
    {
        $node = $this->nodes->byId($id);

        if ($this->framework->isProtected($node)) {
            throw NodeIsProtected::named($node->name);
        }

        $edge = $this->relations->inheritanceEdgeTo($id) ?? throw ImpossibleMove::ofTheRoot();

        // A node dropped onto its own descendant would cut its whole subtree out of the tree,
        // silently. The path already answers this — that is what a materialised path is for.
        if ($newParent->id === $node->id || $newParent->isDescendantOf($node)) {
            throw ImpossibleMove::intoItsOwnDescendant($node->name);
        }

        $moved = $node->movedUnder($newParent->path);

        if ($moved === $node) {
            return $node;
        }

        $this->relations->save(
            $edge->reparentedTo($newParent->id, $this->relations->nextPositionUnder($newParent->id)),
            $edge->version
        );

        $this->nodes->save($moved, $node->version);
        $this->nodes->moveSubtree($node->path, $moved->path);
        $this->changelog->record($id, 'node', $verb, $this->state($node), $this->state($moved), $changeGroup);

        return $moved;
    }

    /**
     * What the changelog freezes about a node at one moment.
     *
     * Deliberately the four fixed attributes and nothing else — the changelog records what the
     * object *was*, and a node is exactly those four things.
     */
    private function state(Node $node): string
    {
        return sprintf('id=%d version=%d name=%s path=%s', $node->id, $node->version, $node->name, $node->path);
    }
}
