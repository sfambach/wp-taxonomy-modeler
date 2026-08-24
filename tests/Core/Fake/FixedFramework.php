<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Repository\FrameworkNodes;

/** A root, a trash and the four branch roots, made once and protected. */
final class FixedFramework implements FrameworkNodes
{
    /**
     * @param array<string,Node> $branchRoots keyed by {@see Branch::value}
     */
    public function __construct(
        private readonly Node $root,
        private readonly Node $trash,
        private readonly array $branchRoots = [],
        private readonly int $installationId = 999000,
    ) {
    }

    public function root(): Node
    {
        return $this->root;
    }

    public function trash(): Node
    {
        return $this->trash;
    }

    public function rootOf(Branch $branch): Node
    {
        return $this->branchRoots[$branch->value];
    }

    public function branchOf(Node $node): ?Branch
    {
        foreach ($this->branchRoots as $value => $root) {
            if ($node->id === $root->id || $node->isDescendantOf($root)) {
                return Branch::from($value);
            }
        }

        return null;
    }


    public function installationId(): int
    {
        return $this->installationId;
    }

    public function isProtected(Node $node): bool
    {
        $ids = [$this->root->id, $this->trash->id];

        foreach ($this->branchRoots as $root) {
            $ids[] = $root->id;
        }

        return in_array($node->id, $ids, true);
    }
}
