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
        // ⚠️ `mandatory` was here until [D-405]: the multiplicity says it, so the key is gone.
        // ⚠️ *Zwei, nicht drei: `hide` ist seit [D-457] eine Spalte und kein Schalter mehr.*
        foreach ([SettingKey::ReadOnly, SettingKey::Persistent] as $key) {
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
    public function a_default_borrows_the_type_of_what_it_sits_on(): void
    {
        // ⚠️ **A default is how a model-level value is expressed at all** — [D-026](../../docs/NewConcept/90-decision-log.md):
        // *at model level there are no values, only defaults*. It briefly carried a prefix's exponent
        // through a read-only default (the withdrawn D-373); that is now `persistent` plus a default
        // (D-377, D-378), and this row still holds the part that was never in question: a default
        // takes the type of whatever it sits on.
        self::assertSame(SettingShape::LikeTheSubject, SettingKey::DefaultValue->shape());
        self::assertSame(SimpleType::Int, SettingKey::DefaultValue->typeFor(SimpleType::Int));
    }

    // ⚠️ **`order` had a test and no reader** ([D-407](../../docs/NewConcept/90-decision-log.md)).
    // The key is gone. Ordering lives in the `position` **column** on `relations`, which
    // `FormRenderer` sorts by and `moveUp`/`moveDown` write — 84 edges use it.
    //
    // ⚠️ *A test asserting the shape of a key nothing consulted was the most honest thing about it: it
    // was right about the shape and the shape was never asked for.*

    #[Test]
    public function an_icon_is_chosen_from_a_set_and_never_typed(): void
    {
        // ⚠️ **This test used to assert `text`, and «characters is the least it can be» was honest
        // while nothing could offer a set.** D-390 ends that: the installation hands the icons in and
        // the chooser draws them, so a text box would be asking somebody to know a Dashicon key by
        // heart. *What D-251 left open — what an icon **is** — is still open; what closed is the
        // question of how a person picks one.*
        self::assertTrue(SettingKey::Icon->shape()->isAChoice());
        self::assertNull(SettingKey::Icon->typeFor(null));
    }

    // -------------------------------------------------- not null, per shape · D-442

    #[Test]
    public function only_a_switch_forbids_an_empty_value(): void
    {
        // The owner drew the line: *«an int setting must have a value» is wrong, null is allowed;
        // «a bool can be unset» is wrong, it can only be 0 or 1 — but all of it is coverable
        // through the same structure.* So `NOT NULL` is one attribute of the shape, and exactly
        // one shape has it.
        $forbidding = array_values(array_filter(
            SettingShape::cases(),
            static fn (SettingShape $shape): bool => ! $shape->allowsNothing()
        ));

        self::assertSame([SettingShape::Switch], $forbidding);
    }
}
