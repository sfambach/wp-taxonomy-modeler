<?php declare(strict_types=1);

namespace Taxmod\Tests\Core\Fake;

use Taxmod\Core\Model\Node;
use Taxmod\Core\Repository\FrameworkNodes;

/** A root and a trash, made once, protected. */
final class FixedFramework implements FrameworkNodes
{
    public function __construct(
        private readonly Node $root,
        private readonly Node $trash,
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

    public function isProtected(Node $node): bool
    {
        return in_array($node->id, [$this->root->id, $this->trash->id], true);
    }
}
