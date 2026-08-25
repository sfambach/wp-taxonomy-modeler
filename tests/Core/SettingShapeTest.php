<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SettingShape;
use Taxmod\Core\Model\SimpleType;

/**
 * What a setting's value looks like — the piece the settings side was missing.
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class SettingShapeTest extends TestCase
{
    #[Test]
    public function every_engine_key_says_what_its_value_looks_like(): void
    {
        // ⚠️ The point of the check is exhaustiveness: a twelfth key added without a shape would
        // arrive on the settings panel as untyped text, which is the second way to draw a field.
        foreach (SettingKey::cases() as $key) {
            self::assertInstanceOf(SettingShape::class, $key->shape(), $key->value);
        }
    }

    #[Test]
    public function the_switches_are_switches(): void
    {
        foreach ([SettingKey::Mandatory, SettingKey::Hide, SettingKey::ReadOnly] as $key) {
            self::assertSame(SettingShape::Switch, $key->shape(), $key->value);
            self::assertSame(SimpleType::Bool, $key->typeFor(null), $key->value);
        }
    }

    #[Test]
    public function the_four_borrowing_keys_take_the_type_of_what_they_sit_on(): void
    {
        // ⚠️ `range_min` on an integer is an integer and on a decimal is a decimal. The type is
        // not a property of the key but of the node being configured.
        $borrowing = [
            SettingKey::DefaultValue,
            SettingKey::RangeMin,
            SettingKey::RangeMax,
            SettingKey::RangeStep,
        ];

        foreach ($borrowing as $key) {
            self::assertSame(SettingShape::LikeTheSubject, $key->shape(), $key->value);
            self::assertSame(SimpleType::Int, $key->typeFor(SimpleType::Int), $key->value);
            self::assertSame(SimpleType::Decimal, $key->typeFor(SimpleType::Decimal), $key->value);
            self::assertSame(SimpleType::Text, $key->typeFor(SimpleType::Text), $key->value);
        }
    }

    #[Test]
    public function a_borrowing_key_on_a_subject_with_no_type_is_not_a_field_at_all(): void
    {
        // ⚠️ Honest rather than convenient: a `default` on a node that is not a simple data type
        // has no shape to be drawn in, and guessing `text` would invite somebody to type a
        // reference as characters.
        self::assertNull(SettingKey::DefaultValue->typeFor(null));
        self::assertNull(SettingKey::RangeMin->typeFor(null));
    }

    #[Test]
    public function the_two_choices_are_choices_and_not_typed_fields(): void
    {
        foreach ([SettingKey::Multiplicity, SettingKey::Renderer, SettingKey::Converter] as $key) {
            self::assertTrue($key->shape()->isAChoice(), $key->value);
            self::assertNull($key->typeFor(SimpleType::Int), $key->value);
        }
    }

    #[Test]
    public function multiplicity_is_a_closed_set_and_a_renderer_name_is_an_open_one(): void
    {
        // ⚠️ Both are chosen, and from different kinds of set: D-351's four constants are fixed,
        // while what a registry answers to grows with every renderer registered (R14).
        self::assertSame(SettingShape::OneOfFour, SettingKey::Multiplicity->shape());
        self::assertSame(SettingShape::ARegisteredName, SettingKey::Renderer->shape());
    }

    #[Test]
    public function a_factor_and_an_offset_are_exact_decimals_of_their_own(): void
    {
        // ⚠️ Their own, not borrowed: the factor from inch to millimetre is `25.4` on a length, and
        // the shape of the value does not change because the dimension does (D-274).
        foreach ([SettingKey::Factor, SettingKey::Offset] as $key) {
            self::assertSame(SettingShape::Exact, $key->shape(), $key->value);
            self::assertSame(SimpleType::Decimal, $key->typeFor(null), $key->value);
            self::assertSame(SimpleType::Decimal, $key->typeFor(SimpleType::Int), $key->value);
        }
    }

    #[Test]
    public function a_prefix_is_a_whole_exponent_and_not_a_factor(): void
    {
        // ⚠️ **The finding, not a preference.** `decimal(30,10)` holds ten decimal places and twenty
        // integer ones, so neither 10⁻²⁴ nor 10²⁴ fits. A prefix **is** a power of ten by
        // definition, so the exponent is exact and small — and it keeps D-039's two axes apart.
        self::assertSame(SettingShape::Whole, SettingKey::PrefixExponent->shape());
        self::assertSame(SimpleType::Int, SettingKey::PrefixExponent->typeFor(null));
    }

    #[Test]
    public function order_has_a_type_of_its_own_rather_than_borrowing_one(): void
    {
        // A position among siblings is a whole number whatever the node happens to hold.
        self::assertSame(SimpleType::Int, SettingKey::Order->typeFor(SimpleType::Text));
    }

    #[Test]
    public function an_icon_is_characters_for_now_and_that_is_a_gap(): void
    {
        // ⚠️ D-251 says the tree row draws an icon *where one is set* and nothing says what an
        // icon **is** — a symbol name, a media reference, a character. Characters is the least it
        // can be, and it is provisional.
        self::assertSame(SimpleType::Text, SettingKey::Icon->typeFor(null));
    }
}
