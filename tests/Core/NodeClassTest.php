<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Exception\UnknownNodeClass;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\NodeClass\Category;
use Taxmod\Core\Model\NodeClass\Choice;
use Taxmod\Core\Model\NodeClass\Constant;
use Taxmod\Core\Model\NodeClass\Contracts;
use Taxmod\Core\Model\NodeClass\Unit;
use Taxmod\Core\Model\NodeClass\UnitValue;
use Taxmod\Core\Model\Type\IntType;
use Taxmod\Core\Model\Type\SpecialisedTypes;
use Taxmod\Core\Model\Type\TextType;

/**
 * Die Knotenklassen und ihre Verträge (D-716, D-719, D-723) — ohne Datenbank, ohne WordPress.
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class NodeClassTest extends TestCase
{
    protected function setUp(): void
    {
        Contracts::forget();
    }

    #[Test]
    public function the_inventory_holds_the_five_classes_and_the_eleven_types(): void
    {
        $all = Contracts::all();

        self::assertCount(5 + count(SpecialisedTypes::CLASSES), $all);
        self::assertSame(count($all), count(array_unique($all)), 'keine Klasse doppelt');

        foreach ([Category::class, Choice::class, Constant::class, Unit::class, UnitValue::class, IntType::class] as $class) {
            self::assertTrue(Contracts::isKnown($class), $class);
        }

        self::assertFalse(Contracts::isKnown(Node::class), 'ein Knoten ist keine Knotenklasse');
    }

    #[Test]
    public function every_class_has_an_icon_and_a_key(): void
    {
        foreach (Contracts::all() as $class) {
            $contract = Contracts::of($class);

            self::assertNotSame('', $contract->icon, $class . ' hat ein Icon (D-723)');
            self::assertNotSame('', $contract->key, $class . ' hat einen Schlüssel');
            self::assertTrue(Contracts::isKnown($contract->defaultChildClass), $class . ': die Vorwahl steht im Inventar');
            self::assertTrue($contract->allowsChild($contract->defaultChildClass), $class . ': die Vorwahl ist erlaubt');
        }
    }

    #[Test]
    public function a_category_allows_everything_and_is_its_own_default(): void
    {
        $contract = Contracts::of(Category::class);

        self::assertSame([], $contract->allowedChildClasses);
        self::assertSame(Category::class, $contract->defaultChildClass);
        self::assertTrue($contract->allowsChild(IntType::class));
        self::assertSame(Contracts::all(), Contracts::childClassesUnder(Category::class));
    }

    #[Test]
    public function a_choice_preselects_a_constant_and_refuses_a_type(): void
    {
        $contract = Contracts::of(Choice::class);

        self::assertSame(Constant::class, $contract->defaultChildClass);
        self::assertTrue($contract->allowsChild(Unit::class), 'Einheiten unter Base units');
        self::assertTrue($contract->allowsChild(Category::class), 'With prefix / Without prefix');
        self::assertFalse($contract->allowsChild(IntType::class));
    }

    #[Test]
    public function a_simple_type_allows_only_itself(): void
    {
        $contract = Contracts::of(IntType::class);

        self::assertSame([IntType::class], $contract->allowedChildClasses);
        self::assertSame(IntType::class, $contract->defaultChildClass);
        self::assertFalse($contract->allowsChild(TextType::class), 'ein Text unter Integer bricht die Vererbung');
        self::assertSame('integer', $contract->key);
    }

    #[Test]
    public function a_contract_is_built_once_and_then_held(): void
    {
        self::assertSame(Contracts::of(Choice::class), Contracts::of(Choice::class));
    }

    #[Test]
    public function an_unknown_class_is_refused(): void
    {
        $this->expectException(UnknownNodeClass::class);

        Contracts::of('Nope\\Nothing');
    }

    #[Test]
    public function a_node_carries_its_class_and_keeps_it_through_every_change(): void
    {
        $node = Node::create(5, 'Prefixes', '1', 1, 0, Choice::class);

        self::assertSame(Choice::class, $node->klasse);
        self::assertSame(Choice::class, $node->renamedTo('Präfixe')->klasse);
        self::assertSame(Choice::class, $node->movedTo(3)->klasse);
        self::assertSame(Choice::class, $node->withHide(true)->klasse);
        self::assertSame(Choice::class, $node->withAssignedId(9)->klasse);
    }

    #[Test]
    public function a_node_without_a_named_class_is_a_category(): void
    {
        self::assertSame(Category::class, Node::create(1, 'Root', null)->klasse);
        self::assertSame(Category::class, Node::fromStorage(2, 1, 'Model', '1.2')->klasse);
    }

    #[Test]
    public function a_type_node_is_its_own_class(): void
    {
        $integer = Node::fromStorage(3, 1, 'Integer', '1.3', IntType::class);

        self::assertInstanceOf(IntType::class, $integer);
        self::assertSame(IntType::class, $integer->klasse);
        self::assertSame(IntType::class, (new IntType())->klasse, 'auch der Steckbrief');
    }
}
