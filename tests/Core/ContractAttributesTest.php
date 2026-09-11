<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Converter\Converter;
use Taxmod\Core\Model\NodeClass\AttributeType;
use Taxmod\Core\Model\NodeClass\Category;
use Taxmod\Core\Model\NodeClass\Constant;
use Taxmod\Core\Model\NodeClass\Contracts;
use Taxmod\Core\Model\NodeClass\NodeAttributes;
use Taxmod\Core\Model\NodeClass\Unit;
use Taxmod\Core\Model\Setting\Conversion;
use Taxmod\Core\Model\Type\BoolType;
use Taxmod\Core\Model\Type\DecimalType;
use Taxmod\Core\Model\Type\IntType;
use Taxmod\Core\Renderer\Renderer;

/**
 * Der Vertrag liest die Attribute einer Klasse per Reflection — einmal, mit Typ, Liste und Vorgabe
 * (Anforderung 2.4, 3.1, 3.6).
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class ContractAttributesTest extends TestCase
{
    protected function setUp(): void
    {
        Contracts::forget();
    }

    #[Test]
    public function every_node_class_has_the_four_base_attributes_at_one_address(): void
    {
        foreach (Contracts::all() as $class) {
            $attribute = Contracts::of($class)->attributes;

            foreach (['renderer', 'converter', 'validator'] as $name) {
                self::assertArrayHasKey($name, $attribute, "$class.$name");
                self::assertSame(NodeAttributes::class, $attribute[$name]->declaredBy, "$class.$name hat die Adresse des Traits");
            }

            // ⚠️ *«display size gibts nur an den simplen datentypen» (D-724).*
            self::assertSame(is_subclass_of($class, \Taxmod\Core\Model\Type\SpecialisedType::class), isset($attribute['display_size']), "$class.display_size nur an einem Typ");
        }
    }

    #[Test]
    public function the_base_attributes_are_typed_as_the_concept_says(): void
    {
        $a = Contracts::of(Category::class)->attributes;

        self::assertSame(AttributeType::Object, $a['renderer']->type);
        self::assertTrue($a['renderer']->list);
        self::assertSame(Renderer::class, $a['renderer']->objectClass);
        self::assertFalse($a['renderer']->allowDuplicates, 'zwei gleiche Renderer sind keine Wahl');

        self::assertSame(Converter::class, $a['converter']->objectClass);
        self::assertTrue($a['converter']->allowDuplicates);

        // ⚠️ *`display_size` erklärt die Typklasse, nicht die Basisklasse (D-724).*
        $t = Contracts::of(\Taxmod\Core\Model\Type\IntType::class)->attributes;
        self::assertSame(AttributeType::Int, $t['display_size']->type);
        self::assertFalse($t['display_size']->list);
        self::assertSame(20, $t['display_size']->default?->int);
        self::assertSame(\Taxmod\Core\Model\Type\SpecialisedType::class . '.display_size', $t['display_size']->address());
    }

    #[Test]
    public function integer_and_decimal_declare_min_max_step_in_their_own_type(): void
    {
        $int     = Contracts::of(IntType::class)->attributes;
        $decimal = Contracts::of(DecimalType::class)->attributes;

        self::assertSame(AttributeType::Int, $int['min']->type);
        self::assertNull($int['min']->default, 'ohne Vorgabe heisst: keine Grenze');
        self::assertSame(1, $int['step']->default?->int);
        self::assertSame(IntType::class . '.min', $int['min']->address());

        self::assertSame(AttributeType::Decimal, $decimal['min']->type);
        self::assertSame('1', $decimal['step']->default?->decimal);
        self::assertSame(DecimalType::class . '.min', $decimal['min']->address(), 'derselbe Name, eine andere Adresse');

        self::assertArrayNotHasKey('min', Contracts::of(BoolType::class)->attributes);
    }

    #[Test]
    public function a_unit_declares_its_prefixes_as_references_to_constants(): void
    {
        $a = Contracts::of(Unit::class)->attributes;

        self::assertSame(AttributeType::Bool, $a['mit_praefix']->type);
        self::assertFalse($a['mit_praefix']->default?->asBool());

        self::assertSame(AttributeType::NodeRef, $a['erlaubte_praefixe']->type);
        self::assertTrue($a['erlaubte_praefixe']->list);
        self::assertSame(Constant::class, $a['erlaubte_praefixe']->refersTo);

        self::assertSame(AttributeType::Text, $a['symbol']->type);
        self::assertSame(AttributeType::Object, $a['umrechnung']->type);
        self::assertSame(Conversion::class, $a['umrechnung']->objectClass);
    }

    #[Test]
    public function a_conversion_is_a_value_class_with_two_decimals(): void
    {
        $vertrag = Contracts::ofValueClass(Conversion::class);

        self::assertSame(AttributeType::Decimal, $vertrag->attributes['factor']->type);
        self::assertSame('1', $vertrag->attributes['factor']->default?->decimal);
        self::assertSame('0', $vertrag->attributes['offset']->default?->decimal);
        self::assertSame([], $vertrag->allowedChildClasses);
        self::assertSame($vertrag, Contracts::ofValueClass(Conversion::class), 'einmal gelesen, dann gehalten');
    }

    #[Test]
    public function a_constant_carries_a_conversion_object(): void
    {
        $a = Contracts::of(Constant::class)->attributes;

        self::assertSame(AttributeType::Object, $a['umrechnung']->type);
        self::assertFalse($a['umrechnung']->list);
        self::assertNull($a['umrechnung']->default);
    }
}
