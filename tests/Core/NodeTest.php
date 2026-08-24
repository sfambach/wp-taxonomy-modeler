<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Exception\InvalidName;
use Taxmod\Core\Model\Node;

/**
 * The four fixed attributes, and the rules that hold them together.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class NodeTest extends TestCase
{
    #[Test]
    public function a_root_has_its_own_id_as_its_path(): void
    {
        $root = Node::create(7, 'Root', null);

        self::assertSame('7', $root->path);
        self::assertNull($root->parentId());
        self::assertSame([], $root->ancestorIds());
    }

    #[Test]
    public function a_child_hangs_its_id_onto_the_parent_path(): void
    {
        $child = Node::create(9, 'Board', '1.4');

        self::assertSame('1.4.9', $child->path);
        self::assertSame(4, $child->parentId());
        self::assertSame([1, 4], $child->ancestorIds());
    }

    #[Test]
    public function a_new_node_starts_at_version_one(): void
    {
        self::assertSame(1, Node::create(1, 'Board', null)->version);
    }

    #[Test]
    public function leading_and_trailing_spaces_are_trimmed_rather_than_refused(): void
    {
        // A trailing space is a mistake nobody makes on purpose, and every later comparison
        // would carry it. Refusing it would be a needless error put in the author's way.
        self::assertSame('Board', Node::create(1, '  Board  ', null)->name);
    }

    #[Test]
    public function a_name_of_nothing_but_spaces_is_refused(): void
    {
        $this->expectException(InvalidName::class);

        Node::create(1, "  \t ", null);
    }

    #[Test]
    public function renaming_raises_the_version(): void
    {
        $node = Node::create(1, 'Board', null);

        self::assertSame(2, $node->renamedTo('Platine')->version);
    }

    #[Test]
    public function renaming_to_the_same_name_changes_nothing_at_all(): void
    {
        // D-282: an unchanged save does not raise the version. The same instance comes back,
        // which is how the caller knows there is nothing to write and nothing to log.
        $node = Node::create(1, 'Board', null);

        self::assertSame($node, $node->renamedTo('Board'));
        self::assertSame($node, $node->renamedTo('  Board  '));
    }

    #[Test]
    public function a_node_is_immutable_so_renaming_leaves_the_original_alone(): void
    {
        $node    = Node::create(1, 'Board', null);
        $renamed = $node->renamedTo('Platine');

        self::assertSame('Board', $node->name);
        self::assertSame('Platine', $renamed->name);
    }

    #[Test]
    public function moving_rewrites_the_path_and_raises_the_version(): void
    {
        $node  = Node::create(9, 'Board', '1');
        $moved = $node->movedUnder('1.2');

        self::assertSame('1.2.9', $moved->path);
        self::assertSame(2, $moved->version);
    }

    #[Test]
    public function moving_to_where_it_already_is_changes_nothing(): void
    {
        $node = Node::create(9, 'Board', '1');

        self::assertSame($node, $node->movedUnder('1'));
    }

    #[Test]
    public function descent_is_read_off_the_path(): void
    {
        $parent = Node::create(4, 'Model', '1');
        $child  = Node::create(9, 'Board', $parent->path);
        $other  = Node::create(5, 'Trash', '1');

        self::assertTrue($child->isDescendantOf($parent));
        self::assertFalse($child->isDescendantOf($other));
        self::assertFalse($parent->isDescendantOf($parent), 'a node is not its own descendant');
    }

    #[Test]
    public function a_sibling_whose_id_starts_the_same_is_not_a_descendant(): void
    {
        // The trap a plain str_starts_with would fall into: 1.4 and 1.44 share a prefix.
        $parent  = Node::create(4, 'Model', '1');
        $sibling = Node::create(44, 'Other', '1');

        self::assertFalse($sibling->isDescendantOf($parent));
    }
}
