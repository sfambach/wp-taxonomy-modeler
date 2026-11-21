<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeClass\Category;
use Taxmod\Core\Model\NodeClass\Constant;
use Taxmod\Core\Model\NodeClass\Contracts;
use Taxmod\Core\Model\NodeClass\Unit;
use Taxmod\Core\Model\NodeClass\UnitValue;
use Taxmod\Core\Model\Setting\ListEntry;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\SettingsEditor;
use Taxmod\Core\Service\SettingsResolver;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\InMemorySettings;
use Taxmod\Tests\Core\Fake\RecordedChanges;

/**
 * A child data element only narrows its parent (D-1559): without a list of its own it allows the parent's units and keeps its
 * preselected prefix, and a list of its own beyond the parent's is reported.
 *
 * @see docs/NewConcept/90-decision-log.md D-1559
 */
final class UnitInheritanceTest extends TestCase
{
    private SettingsResolver $resolver;
    private SettingsEditor $editor;
    private InMemoryNodes $nodes;
    private Node $volt;
    private Node $ampere;
    private Node $spannung;
    private Node $maximal;
    private Node $milli;

    protected function setUp(): void
    {
        Contracts::forget();

        $this->nodes    = new InMemoryNodes();
        $settings       = new InMemorySettings();
        $this->resolver = new SettingsResolver($settings, $this->nodes, ShippedRenderers::registry(), ShippedConverters::registry());
        $this->editor   = new SettingsEditor($settings, $this->nodes, $this->resolver, ShippedRenderers::registry(), ShippedConverters::registry(), new RecordedChanges());

        $this->nodes->add(Node::create(1, 'Root', null));
        $this->volt     = $this->nodes->add(Node::create(2, 'Volt', '1', 1, 0, Unit::class));
        $this->ampere   = $this->nodes->add(Node::create(3, 'Ampere', '1', 1, 1, Unit::class));
        $this->milli    = $this->nodes->add(Node::create(4, 'milli', '1', 1, 2, Constant::class));
        $wert           = $this->nodes->add(Node::create(5, 'Einheitenwert', '1', 1, 3, UnitValue::class));
        $this->spannung = $this->nodes->add(Node::create(6, 'Spannung', '1.5', 5, 0, UnitValue::class));
        $this->maximal  = $this->nodes->add(Node::create(7, 'Maximalspannung', '1.5.6', 6, 0, UnitValue::class));

        $this->editor->setMembers($this->spannung, UnitValue::ERLAUBTE_EINHEITEN, [$this->volt->id]);
    }

    /**
     * @param  list<ListEntry> $entries
     * @return list<int>
     */
    private static function ids(array $entries): array
    {
        return array_map(static fn (ListEntry $e): int => (int) $e->reference, $entries);
    }

    #[Test]
    public function a_child_without_a_list_allows_the_units_of_its_parent(): void
    {
        self::assertSame([], $this->resolver->listOf($this->maximal, UnitValue::ERLAUBTE_EINHEITEN), 'its own list stays empty');
        self::assertSame([$this->volt->id], self::ids($this->resolver->allowedUnitsOf($this->maximal)));
    }

    #[Test]
    public function a_grandchild_reaches_the_nearest_ancestor_with_a_list(): void
    {
        $enkel = $this->nodes->add(Node::create(8, 'Maximalspannung DC', '1.5.6.7', 7, 0, UnitValue::class));

        self::assertSame([$this->volt->id], self::ids($this->resolver->allowedUnitsOf($enkel)));
    }

    #[Test]
    public function the_walk_stops_at_a_node_of_another_class(): void
    {
        $ast  = $this->nodes->add(Node::create(9, 'Ast', '1.5.6', 6, 1, Category::class));
        $kind = $this->nodes->add(Node::create(10, 'Unter Ast', '1.5.6.9', 9, 0, UnitValue::class));

        self::assertSame([], $this->resolver->allowedUnitsOf($kind));
    }

    #[Test]
    public function a_child_keeps_the_preselected_prefix_of_its_parent(): void
    {
        $this->editor->put($this->spannung, UnitValue::VORGABE_PRAEFIX, (string) $this->milli->id);

        self::assertSame($this->milli->id, $this->resolver->defaultPrefixOf($this->maximal));
    }

    #[Test]
    public function a_list_of_its_own_that_narrows_is_fine_and_one_that_widens_is_reported(): void
    {
        $this->editor->setMembers($this->maximal, UnitValue::ERLAUBTE_EINHEITEN, [$this->volt->id]);
        self::assertSame([], $this->resolver->unitsBeyondParent($this->maximal));

        $this->editor->setMembers($this->maximal, UnitValue::ERLAUBTE_EINHEITEN, [$this->volt->id, $this->ampere->id]);
        self::assertSame([$this->ampere->id], $this->resolver->unitsBeyondParent($this->maximal));
    }
}
