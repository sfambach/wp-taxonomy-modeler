<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Exception\NodeIsProtected;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Repository\Changelog;
use Taxmod\Core\Repository\FrameworkNodes;
use Taxmod\Core\Repository\IdentityAllocator;
use Taxmod\Core\Repository\NodeRepository;

/**
 * Everything a person can do to the shape of the model: make a node, rename it, throw it away.
 *
 * ⚠️ **Deletion is two stages, and only the second is irreversible** (D-123). Parking moves a
 * node under the trash; it stays a real node, so nothing that pointed at it dangles and a
 * conflict can be sorted out afterwards in peace instead of in a dialog blocking the delete.
 *
 * ```mermaid
 * flowchart LR
 *   A[in the model] -->|park| B[under the trash]
 *   B -->|restore| A
 *   B -->|purge| C[gone]
 * ```
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class ModelEditor
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly IdentityAllocator $identities,
        private readonly FrameworkNodes $framework,
        private readonly Changelog $changelog,
    ) {
    }

    public function createNode(string $name, int $parentId): Node
    {
        $parent = $this->nodes->byId($parentId);
        $node   = Node::create($this->identities->next(), $name, $parent->path);

        $this->nodes->add($node);
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

    /**
     * Park a node and everything under it.
     *
     * ⚠️ **The old path is written to the changelog and nowhere else.** That is what a restore
     * reads, and it is why the node itself needs no `parked_from` column — one place owns each
     * fact.
     */
    public function moveToTrash(int $id): Node
    {
        $node = $this->nodes->byId($id);

        if ($this->framework->isProtected($node)) {
            throw NodeIsProtected::named($node->name);
        }

        $trash  = $this->framework->trash();
        $parked = $node->movedUnder($trash->path);

        $this->nodes->save($parked, $node->version);
        $this->nodes->moveSubtree($node->path, $parked->path);
        $this->changelog->record($id, 'node', 'parked', $this->state($node), $this->state($parked));

        return $parked;
    }

    /** @return list<Node> */
    public function childrenOf(int $parentId): array
    {
        return $this->nodes->childrenOf($this->nodes->byId($parentId));
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
