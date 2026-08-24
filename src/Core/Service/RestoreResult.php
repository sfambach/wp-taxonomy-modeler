<?php declare(strict_types=1);

namespace Taxmod\Core\Service;

use Taxmod\Core\Model\Node;

/**
 * What came back, and what deliberately did not.
 *
 * ⚠️ **The second half is the point.** Restoring is undo (D-347), so a child promoted by
 * *delete only this node* comes back with it — **unless somebody has moved, renamed or deleted
 * it since**. Those are left where they are, because bringing them back would overwrite a newer,
 * deliberate decision with an older one. **A restore that quietly relocates somebody's work is
 * the worse failure than one that leaves two nodes out and says so** — which is why this type
 * exists instead of the method simply returning a node.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class RestoreResult
{
    /**
     * @param list<string> $leftBehind Names of children that were not put back, because they
     *                                 are no longer where the promotion left them.
     */
    public function __construct(
        public readonly Node $node,
        public readonly array $broughtBack,
        public readonly array $leftBehind,
    ) {
    }

    public function everythingCameBack(): bool
    {
        return $this->leftBehind === [];
    }
}
