<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SettingRecord;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Settings;
use Taxmod\Tests\Core\Fake\CountingIdentities;
use Taxmod\Tests\Core\Fake\FixedFramework;
use Taxmod\Tests\Core\Fake\InMemoryLabels;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\InMemoryRelations;
use Taxmod\Tests\Core\Fake\InMemorySettings;
use Taxmod\Tests\Core\Fake\RecordedChanges;

/**
 * Copying a node: what travels with it, and to which address.
 *
 * ⚠️ **`duplicate()` had no test at all**, which is [list row 43](../../../docs/NewConcept/97-implementation-plan.md#the-working-list)'s
 * real cause: the `path` argument was simply not passed, so a copy kept the node's own values and
 * dropped everything it said about its **individual attributes** — and nothing noticed for days.
 *
 * ⚠️ **Which of these actually carry weight, measured by putting the fault back.** *With the old
 * behaviour restored, **three of the five fail**: the per-attribute setting, the per-attribute label
 * and the inherited address. The other two stay green either way — a dropped path trivially satisfies
 * «not at the original's address», and a node's own row has no path to lose. **They are worth keeping
 * as the shape of the contract and they are not the proof**, which is exactly the kind of thing a
 * green suite hides unless somebody says it out loud.*
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class DuplicateTest extends TestCase
{
    private const INSTALLATION = 999000;

    private InMemoryNodes $nodes;
    private InMemoryRelations $edges;
    private InMemorySettings $stored;
    private InMemoryLabels $labelStore;
    private ModelEditor $editor;
    private Settings $settings;
    /** @var array<string,Node> */
    private array $branchRoot = [];

    protected function setUp(): void
    {
        $this->edges      = new InMemoryRelations();
        $this->nodes      = new InMemoryNodes($this->edges);
        $this->stored     = new InMemorySettings();
        $this->labelStore = new InMemoryLabels();
        $identities       = new CountingIdentities();

        $make = function (string $name, ?Node $parent) use ($identities): Node {
            $node = Node::create($identities->next(), $name, $parent?->path);
            $this->nodes->add($node);

            if ($parent !== null) {
                $this->edges->add(Relation::inheritance(
                    $identities->next(),
                    $parent->id,
                    $node->id,
                    $this->edges->nextPositionUnder($parent->id)
                ));
            }

            return $node;
        };

        $root  = $make('Root', null);
        $trash = $make('Trash', $root);

        $this->branchRoot['model']        = $make('Model', $root);
        $this->branchRoot['compositions'] = $make('Compositions', $root);

        $primitives = $make('Primitives', $root);

        $this->branchRoot['data-types'] = $make('Data Types', $primitives);
        $this->branchRoot['constants']  = $make('Constants', $primitives);

        $framework = new FixedFramework($root, $trash, $this->branchRoot, self::INSTALLATION);

        $this->settings = new Settings($this->stored, $this->nodes, $framework);

        $this->editor = new ModelEditor(
            $this->nodes,
            $this->edges,
            $identities,
            $framework,
            new RecordedChanges(),
            $this->stored,
            $this->labelStore
        );
    }

    private function thing(string $name): Node
    {
        return $this->editor->createNode($name, $this->branchRoot['model']->id);
    }

    private function type(string $name): Node
    {
        return $this->editor->createNode($name, $this->branchRoot['data-types']->id);
    }

    /** The copy's own attribute of that name — the edge the remap has to have produced. */
    private function fieldNamed(Node $node, string $name): Relation
    {
        foreach ($this->edges->fieldEdgesOf([$node->id]) as $edge) {
            if ($edge->name === $name) {
                return $edge;
            }
        }

        self::fail(sprintf('«%s» has no attribute named «%s»', $node->name, $name));
    }

    // ------------------------------------------- what the original said about one attribute

    #[Test]
    public function a_per_attribute_setting_travels_to_the_copys_own_attribute(): void
    {
        $part  = $this->thing('Part');
        $count = $this->editor->addField($part->id, $this->type('int')->id, 'count');

        // ⚠️ *«Part's default **for that attribute**», which is what the path column exists to keep
        // apart from «Part's own default» ([D-413](../../../docs/NewConcept/90-decision-log.md)).*
        $this->stored->put(new SettingRecord($part->id, SettingKey::DefaultValue->value, TypedValue::ofInt(7), (string) $count->id));

        $copy     = $this->editor->duplicate($part->id);
        $copyEdge = $this->fieldNamed($copy, 'count');

        // The copy really did get a new edge — otherwise this test proves nothing.
        self::assertNotSame($count->id, $copyEdge->id);

        $atCopy = $this->settings->resolve([$copy->id], (string) $copyEdge->id);

        self::assertArrayHasKey(SettingKey::DefaultValue->value, $atCopy);
        self::assertSame(7, $atCopy[SettingKey::DefaultValue->value]->value->int);
    }

    #[Test]
    public function the_copy_does_not_answer_at_the_originals_address(): void
    {
        $part  = $this->thing('Part');
        $count = $this->editor->addField($part->id, $this->type('int')->id, 'count');

        $this->stored->put(new SettingRecord($part->id, SettingKey::DefaultValue->value, TypedValue::ofInt(7), (string) $count->id));

        $copy = $this->editor->duplicate($part->id);

        // ⚠️ **The second wrong answer, ruled out.** *Passing the path through unchanged would have
        // been «restored» and still wrong: the row would name the **original's** edge, so the copy
        // would answer for an attribute that is not its own.*
        $rows = array_values(array_filter(
            $this->stored->ownedBy($copy->id),
            static fn (SettingRecord $one): bool => $one->path === (string) $count->id
        ));

        self::assertSame([], $rows);
    }

    #[Test]
    public function a_label_written_for_one_attribute_travels_the_same_way(): void
    {
        $part  = $this->thing('Part');
        $count = $this->editor->addField($part->id, $this->type('int')->id, 'count');

        // ⚠️ *`labels.path` addresses a place exactly as `settings.path` does, and `copyLabels()` had
        // the identical fault one line over — unmentioned by the row that found the first one.*
        $this->labelStore->put(new Label($part->id, (string) $count->id, 901, '', 'de_DE', 'Stückzahl'));

        $copy     = $this->editor->duplicate($part->id);
        $copyEdge = $this->fieldNamed($copy, 'count');

        $paths = array_map(
            static fn (Label $one): string => $one->path,
            array_values($this->labelStore->forOwners([$copy->id]))
        );

        self::assertContains((string) $copyEdge->id, $paths);
        self::assertNotContains((string) $count->id, $paths);
    }

    #[Test]
    public function a_setting_about_the_node_itself_keeps_the_empty_path(): void
    {
        $part = $this->thing('Part');

        $this->stored->put(new SettingRecord($part->id, SettingKey::Icon->value, TypedValue::ofText('screenoptions')));

        $copy = $this->editor->duplicate($part->id);

        $own = $this->settings->resolve([$copy->id]);

        self::assertSame('screenoptions', $own[SettingKey::Icon->value]->value->text ?? null);
    }

    #[Test]
    public function an_inherited_attributes_address_is_kept_because_the_copy_inherits_the_same_edge(): void
    {
        $thing = $this->thing('Bauteil');
        $shared = $this->editor->addField($thing->id, $this->type('text')->id, 'note');

        // A child of the thing, which inherits that attribute rather than declaring it.
        $special = $this->editor->createNode('Widerstand', $thing->id);

        // ⚠️ *The child's own row **at the inherited edge's address** — a legitimate override.*
        $this->stored->put(new SettingRecord($special->id, SettingKey::DefaultValue->value, TypedValue::ofText('x'), (string) $shared->id));

        $copy = $this->editor->duplicate($special->id);

        // ⚠️ **Unmapped, therefore unchanged — and that is right, not a gap.** *An inherited attribute
        // **is** the same edge ([D-405](../../../docs/NewConcept/90-decision-log.md)), and the copy
        // sits under the same parent, so it inherits that very edge. The address is already its own.*
        $atCopy = $this->settings->resolve([$copy->id], (string) $shared->id);

        self::assertSame('x', $atCopy[SettingKey::DefaultValue->value]->value->text ?? null);
    }
}
