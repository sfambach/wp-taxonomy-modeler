<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Service\Rendering;

/** D-1546: a choice offers only what lies under the roots a structure setting names; the way down to them is context, unpickable. */
final class ChoiceRootsTest extends TestCase
{
    #[Test]
    public function only_the_roots_and_below_are_offered_and_their_ancestors_are_context(): void
    {
        $model    = Node::create(402, 'Model', '1', 1);
        $material = Node::create(407, 'Material', $model->path, 402);
        $parts    = Node::create(3634, 'Bauteile', $material->path, 407);
        $stock    = Node::create(120, 'Stock', $model->path, 402);
        $places   = Node::create(146, 'Lagerorte', $stock->path, 120);
        $hardware = Node::create(18507, 'Hardware-Projekte', $model->path, 402);
        $deep     = Node::create(500, 'Tief', $places->path, 146);
        $inner    = Node::create(501, 'Innen', $deep->path, 500);
        $rows     = array_map(static fn (Node $n): array => ['node' => $n], [$model, $material, $parts, $stock, $places, $deep, $inner, $hardware]);

        [$kept, $context] = Rendering::rowsWithin($rows, [407, 18507, 500]);

        self::assertSame([402, 407, 3634, 120, 146, 500, 501, 18507], array_map(static fn (array $r): int => $r['node']->id, $kept));
        self::assertEqualsCanonicalizing([1, 402, 120, 146], $context, 'the way down to a root is shown, never chosen');

        [$none] = Rendering::rowsWithin($rows, [999]);
        self::assertSame([], $none, 'a root outside the target offers nothing');
    }
}
