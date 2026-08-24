<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Exception\CannotWiden;
use Taxmod\Core\Exception\ReservedKey;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SettingValue;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Settings;
use Taxmod\Tests\Core\Fake\CountingIdentities;
use Taxmod\Tests\Core\Fake\FixedFramework;
use Taxmod\Tests\Core\Fake\InMemoryNodes;
use Taxmod\Tests\Core\Fake\InMemoryRelations;
use Taxmod\Tests\Core\Fake\InMemorySettings;
use Taxmod\Tests\Core\Fake\RecordedChanges;

/**
 * The resolution chain: installation → model root → ancestors → node → use site.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class SettingsTest extends TestCase
{
    private const INSTALLATION = 999000;

    private InMemoryNodes $nodes;
    private InMemoryRelations $edges;
    private InMemorySettings $stored;
    private Settings $settings;
    private ModelEditor $editor;
    private Node $root;
    /** @var array<string,Node> */
    private array $branchRoot = [];

    protected function setUp(): void
    {
        $this->edges  = new InMemoryRelations();
        $this->nodes  = new InMemoryNodes($this->edges);
        $this->stored = new InMemorySettings();
        $identities   = new CountingIdentities();

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

        $this->root = $make('Root', null);
        $trash      = $make('Trash', $this->root);

        $this->branchRoot['model']        = $make('Model', $this->root);
        $this->branchRoot['compositions'] = $make('Compositions', $this->root);

        $primitives = $make('Primitives', $this->root);

        $this->branchRoot['data-types'] = $make('Data Types', $primitives);
        $this->branchRoot['constants']  = $make('Constants', $primitives);

        $framework = new FixedFramework($this->root, $trash, $this->branchRoot, self::INSTALLATION);

        $this->editor   = new ModelEditor($this->nodes, $this->edges, $identities, $framework, new RecordedChanges());
        $this->settings = new Settings($this->stored, $this->nodes, $framework);
    }

    private function type(string $name): Node
    {
        return $this->editor->createNode($name, $this->branchRoot['data-types']->id);
    }

    // ------------------------------------------------------------- the walk

    #[Test]
    public function the_chain_starts_at_the_installation_and_follows_the_path(): void
    {
        $type = $this->type('Text');

        self::assertSame(
            [self::INSTALLATION, ...$type->ancestorIds(), $type->id],
            $this->settings->chainFor($type)
        );
    }

    #[Test]
    public function the_use_site_is_the_last_link(): void
    {
        // D-032: *a configured default plus a choice in the moment* is the top and the bottom
        // of one walk, not two mechanisms.
        $thing = $this->editor->createNode('Thing', $this->branchRoot['model']->id);
        $type  = $this->type('Text');
        $edge  = $this->editor->addAttribute($thing->id, $type->id, 'description');

        $chain = $this->settings->chainForUseSite($edge);

        self::assertSame(self::INSTALLATION, $chain[0]);
        self::assertSame($edge->id, end($chain));
    }

    #[Test]
    public function a_value_set_at_the_installation_reaches_everything(): void
    {
        $type = $this->type('Text');

        $this->settings->put([self::INSTALLATION], SettingKey::Renderer->value, SettingValue::ofText('plain'));

        $resolved = $this->settings->resolve($this->settings->chainFor($type));

        self::assertSame('plain', $resolved[SettingKey::Renderer->value]->value->text);
        self::assertTrue($resolved[SettingKey::Renderer->value]->isInherited());
    }

    #[Test]
    public function the_nearer_link_wins(): void
    {
        $type  = $this->type('Text');
        $chain = $this->settings->chainFor($type);

        $this->settings->put([self::INSTALLATION], SettingKey::Renderer->value, SettingValue::ofText('plain'));
        $this->settings->put($chain, SettingKey::Renderer->value, SettingValue::ofText('markdown'));

        $resolved = $this->settings->resolve($chain);

        self::assertSame('markdown', $resolved[SettingKey::Renderer->value]->value->text);
        self::assertTrue($resolved[SettingKey::Renderer->value]->setHere);
    }

    #[Test]
    public function the_walk_is_key_by_key_so_a_consumer_may_take_a_mix(): void
    {
        // D-079, D-093: not one link winning the whole set — the renderer from up there, the
        // icon from here.
        $type  = $this->type('Text');
        $chain = $this->settings->chainFor($type);

        $this->settings->put([self::INSTALLATION], SettingKey::Renderer->value, SettingValue::ofText('plain'));
        $this->settings->put($chain, SettingKey::Icon->value, SettingValue::ofText('dashicons-editor-quote'));

        $resolved = $this->settings->resolve($chain);

        self::assertSame(self::INSTALLATION, $resolved[SettingKey::Renderer->value]->fromOwnerId);
        self::assertSame($type->id, $resolved[SettingKey::Icon->value]->fromOwnerId);
    }

    #[Test]
    public function an_override_at_the_use_site_beats_the_type(): void
    {
        $thing = $this->editor->createNode('Thing', $this->branchRoot['model']->id);
        $type  = $this->type('Text');
        $edge  = $this->editor->addAttribute($thing->id, $type->id, 'description');

        $this->settings->put($this->settings->chainFor($type), SettingKey::Renderer->value, SettingValue::ofText('plain'));
        $this->settings->put($this->settings->chainForUseSite($edge), SettingKey::Renderer->value, SettingValue::ofText('markdown'));

        $atType = $this->settings->resolve($this->settings->chainFor($type));
        $atSite = $this->settings->resolve($this->settings->chainForUseSite($edge));

        self::assertSame('plain', $atType[SettingKey::Renderer->value]->value->text, 'the type is untouched');
        self::assertSame('markdown', $atSite[SettingKey::Renderer->value]->value->text);
    }

    // ------------------------------------------------- reset versus nothing

    #[Test]
    public function resetting_makes_it_inherited_again(): void
    {
        $type  = $this->type('Text');
        $chain = $this->settings->chainFor($type);

        $this->settings->put([self::INSTALLATION], SettingKey::Renderer->value, SettingValue::ofText('plain'));
        $this->settings->put($chain, SettingKey::Renderer->value, SettingValue::ofText('markdown'));
        $this->settings->reset($type->id, SettingKey::Renderer->value);

        $resolved = $this->settings->resolve($chain);

        self::assertSame('plain', $resolved[SettingKey::Renderer->value]->value->text);
        self::assertTrue($resolved[SettingKey::Renderer->value]->isInherited());
    }

    #[Test]
    public function setting_nothing_is_not_the_same_as_resetting(): void
    {
        // ⚠️ D-266, and the whole reason the two are separate acts: *deliberately nothing here*
        // must survive a later change at the base, and *inherited* must not.
        $type  = $this->type('Text');
        $chain = $this->settings->chainFor($type);

        $this->settings->put([self::INSTALLATION], SettingKey::Renderer->value, SettingValue::ofText('plain'));
        $this->settings->put($chain, SettingKey::Renderer->value, SettingValue::nothing());

        $resolved = $this->settings->resolve($chain);

        self::assertTrue($resolved[SettingKey::Renderer->value]->value->isNothing());
        self::assertTrue($resolved[SettingKey::Renderer->value]->setHere);
    }

    #[Test]
    public function a_later_change_at_the_base_does_not_reach_a_deliberate_nothing(): void
    {
        $type  = $this->type('Text');
        $chain = $this->settings->chainFor($type);

        $this->settings->put($chain, SettingKey::Renderer->value, SettingValue::nothing());
        $this->settings->put([self::INSTALLATION], SettingKey::Renderer->value, SettingValue::ofText('changed later'));

        self::assertTrue($this->settings->resolve($chain)[SettingKey::Renderer->value]->value->isNothing());
    }

    #[Test]
    public function a_later_change_at_the_base_does_reach_a_reset_one(): void
    {
        $type  = $this->type('Text');
        $chain = $this->settings->chainFor($type);

        $this->settings->put($chain, SettingKey::Renderer->value, SettingValue::ofText('mine'));
        $this->settings->reset($type->id, SettingKey::Renderer->value);
        $this->settings->put([self::INSTALLATION], SettingKey::Renderer->value, SettingValue::ofText('changed later'));

        self::assertSame('changed later', $this->settings->resolve($chain)[SettingKey::Renderer->value]->value->text);
    }

    #[Test]
    public function storage_is_sparse(): void
    {
        // D-015: only what differs is written. Two links, two rows — not one row per link of
        // the chain.
        $type = $this->type('Text');

        $this->settings->put([self::INSTALLATION], SettingKey::Renderer->value, SettingValue::ofText('plain'));
        $this->settings->put($this->settings->chainFor($type), SettingKey::Icon->value, SettingValue::ofText('x'));

        self::assertSame(2, $this->stored->count());
    }

    // ------------------------------------------------- bounding and choosing

    #[Test]
    public function a_choosing_setting_may_become_anything(): void
    {
        $type  = $this->type('Text');
        $chain = $this->settings->chainFor($type);

        $this->settings->put([self::INSTALLATION], SettingKey::DefaultValue->value, SettingValue::ofText('a'));
        $this->settings->put($chain, SettingKey::DefaultValue->value, SettingValue::ofText('b'));

        self::assertSame('b', $this->settings->resolve($chain)[SettingKey::DefaultValue->value]->value->text);
    }

    #[Test]
    public function a_maximum_may_be_lowered(): void
    {
        $type  = $this->type('Text');
        $chain = $this->settings->chainFor($type);

        $this->settings->put([self::INSTALLATION], SettingKey::MultiplicityMax->value, SettingValue::ofInt(5));
        $this->settings->put($chain, SettingKey::MultiplicityMax->value, SettingValue::ofInt(2));

        self::assertSame(2, $this->settings->resolve($chain)[SettingKey::MultiplicityMax->value]->value->int);
    }

    #[Test]
    public function a_maximum_may_not_be_raised(): void
    {
        $type  = $this->type('Text');
        $chain = $this->settings->chainFor($type);

        $this->settings->put([self::INSTALLATION], SettingKey::MultiplicityMax->value, SettingValue::ofInt(2));

        $this->expectException(CannotWiden::class);

        $this->settings->put($chain, SettingKey::MultiplicityMax->value, SettingValue::ofInt(5));
    }

    #[Test]
    public function a_minimum_may_be_raised_but_not_lowered(): void
    {
        $type  = $this->type('Text');
        $chain = $this->settings->chainFor($type);

        $this->settings->put([self::INSTALLATION], SettingKey::MultiplicityMin->value, SettingValue::ofInt(1));
        $this->settings->put($chain, SettingKey::MultiplicityMin->value, SettingValue::ofInt(2));

        self::assertSame(2, $this->settings->resolve($chain)[SettingKey::MultiplicityMin->value]->value->int);

        $this->expectException(CannotWiden::class);

        $this->settings->put($chain, SettingKey::MultiplicityMin->value, SettingValue::ofInt(0));
    }

    #[Test]
    public function what_an_ancestor_declares_mandatory_stays_mandatory(): void
    {
        // ⚠️ D-311, from the owner's own case: a bird can fly — but what about a penguin? The
        // answer is that *every bird has a name* must keep meaning something, or a
        // classification guarantees nothing about the group it names.
        $type  = $this->type('Text');
        $chain = $this->settings->chainFor($type);

        $this->settings->put([self::INSTALLATION], SettingKey::Mandatory->value, SettingValue::ofBool(true));

        $this->expectException(CannotWiden::class);

        $this->settings->put($chain, SettingKey::Mandatory->value, SettingValue::ofBool(false));
    }

    #[Test]
    public function something_not_yet_mandatory_may_become_mandatory(): void
    {
        $type  = $this->type('Text');
        $chain = $this->settings->chainFor($type);

        $this->settings->put([self::INSTALLATION], SettingKey::Mandatory->value, SettingValue::ofBool(false));
        $this->settings->put($chain, SettingKey::Mandatory->value, SettingValue::ofBool(true));

        self::assertTrue($this->settings->resolve($chain)[SettingKey::Mandatory->value]->value->asBool());
    }

    #[Test]
    public function what_is_hidden_above_cannot_be_revealed_below(): void
    {
        $type  = $this->type('Text');
        $chain = $this->settings->chainFor($type);

        $this->settings->put([self::INSTALLATION], SettingKey::Hide->value, SettingValue::ofBool(true));

        $this->expectException(CannotWiden::class);

        $this->settings->put($chain, SettingKey::Hide->value, SettingValue::ofBool(false));
    }

    #[Test]
    public function a_bound_with_nothing_above_it_may_be_set_freely(): void
    {
        $type  = $this->type('Text');
        $chain = $this->settings->chainFor($type);

        $this->settings->put($chain, SettingKey::MultiplicityMax->value, SettingValue::ofInt(99));

        self::assertSame(99, $this->settings->resolve($chain)[SettingKey::MultiplicityMax->value]->value->int);
    }

    #[Test]
    public function decimals_are_compared_as_numbers_not_as_text(): void
    {
        // ⚠️ `9` is not greater than `10` merely because it starts with a nine.
        $type  = $this->type('Text');
        $chain = $this->settings->chainFor($type);

        $this->settings->put([self::INSTALLATION], SettingKey::RangeMax->value, SettingValue::ofDecimal('10'));
        $this->settings->put($chain, SettingKey::RangeMax->value, SettingValue::ofDecimal('9'));

        self::assertSame('9', $this->settings->resolve($chain)[SettingKey::RangeMax->value]->value->decimal);
    }

    // ------------------------------------------------------- reserved names

    #[Test]
    public function a_free_setting_cannot_take_one_of_the_engines_names(): void
    {
        // D-084: an author who defines a setting called `hide` would silently break rendering.
        $type = $this->type('Text');

        $this->expectException(ReservedKey::class);

        $this->settings->declareFree($this->settings->chainFor($type), 'hide', SettingValue::ofBool(true));
    }

    #[Test]
    public function a_name_of_its_own_is_allowed(): void
    {
        $type  = $this->type('Text');
        $chain = $this->settings->chainFor($type);

        $this->settings->declareFree($chain, 'colour of the label', SettingValue::ofText('green'));

        self::assertSame('green', $this->settings->resolve($chain)['colour of the label']->value->text);
    }

    #[Test]
    public function a_free_setting_is_not_bounded(): void
    {
        // Only the engine's keys carry a direction; a free one is nobody's business but its
        // author's.
        $type  = $this->type('Text');
        $chain = $this->settings->chainFor($type);

        $this->settings->declareFree([self::INSTALLATION], 'my number', SettingValue::ofInt(1));
        $this->settings->declareFree($chain, 'my number', SettingValue::ofInt(999));

        self::assertSame(999, $this->settings->resolve($chain)['my number']->value->int);
    }

    #[Test]
    public function every_engine_key_says_which_way_it_may_move(): void
    {
        self::assertTrue(SettingKey::Mandatory->isBounding());
        self::assertTrue(SettingKey::Hide->isBounding());
        self::assertTrue(SettingKey::MultiplicityMax->isBounding());
        self::assertFalse(SettingKey::DefaultValue->isBounding());
        self::assertFalse(SettingKey::Renderer->isBounding());
        self::assertFalse(SettingKey::Icon->isBounding());
    }
}
