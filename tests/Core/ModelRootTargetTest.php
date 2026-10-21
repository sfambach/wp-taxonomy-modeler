<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Exception\NotAPossibleTarget;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Tests\Core\Fake\CountingIdentities;
use Taxmod\Tests\Core\Fake\FixedFramework;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\InMemoryRelations;
use Taxmod\Tests\Core\Fake\RecordedChanges;

/**
 * Only a reference may point at the root of `Model` — «any record under Model» (D-958, F3a).
 *
 * @see docs/pakete/lager/konzept.md
 */
final class ModelRootTargetTest extends TestCase
{
    private ModelEditor $editor;
    private Node $model;
    private Node $material;

    protected function setUp(): void
    {
        $relations  = new InMemoryRelations();
        $nodes      = new InMemoryNodes($relations);
        $identities = new CountingIdentities();

        $root  = Node::create($identities->next(), 'Root', null);
        $trash = Node::create($identities->next(), 'Trash', $root->path, $root->id, 0);
        $nodes->add($root);
        $nodes->add($trash);

        $vorab       = new ModelEditor($nodes, $relations, new FixedFramework($root, $trash), new RecordedChanges());
        $this->model = $vorab->createNode('Model', $root->id);

        $this->editor   = new ModelEditor($nodes, $relations, new FixedFramework($root, $trash, [Branch::Model->value => $this->model]), new RecordedChanges());
        $this->material = $this->editor->createNode('Material', $this->model->id);
    }

    #[Test]
    public function a_reference_may_point_at_any_record_under_model(): void
    {
        $feld = $this->editor->addField($this->material->id, $this->model->id, 'Bezug Ding', RelationKind::Aggregation);

        self::assertSame($this->model->id, $feld->toNodeId);
        self::assertSame(RelationKind::Aggregation, $feld->kind);
    }

    #[Test]
    public function without_a_kind_the_field_on_model_is_a_reference(): void
    {
        self::assertSame(RelationKind::Aggregation, $this->editor->addField($this->material->id, $this->model->id, 'Bezug Ding')->kind);
    }

    #[Test]
    public function a_part_of_model_is_refused(): void
    {
        $this->expectException(NotAPossibleTarget::class);

        $this->editor->addField($this->material->id, $this->model->id, 'Teil', RelationKind::Composition);
    }

    #[Test]
    public function a_reference_on_model_cannot_become_a_part(): void
    {
        $feld = $this->editor->addField($this->material->id, $this->model->id, 'Bezug Ding', RelationKind::Aggregation);

        $this->expectException(NotAPossibleTarget::class);

        $this->editor->setKind($this->material->id, $feld->id, RelationKind::Composition);
    }

    #[Test]
    public function the_root_above_model_stays_barred(): void
    {
        $this->expectException(NotAPossibleTarget::class);

        $this->editor->addField($this->material->id, $this->model->parentNodeId ?? 0, 'Wurzel', RelationKind::Aggregation);
    }
}
