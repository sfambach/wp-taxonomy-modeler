<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\SettingCategory;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\SimpleType;

/**
 * Whose setting is this — the axis the panel groups by (D-390).
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class SettingCategoryTest extends TestCase
{
    #[Test]
    public function every_key_lands_somewhere_for_every_subject(): void
    {
        // ⚠️ The point is **exhaustiveness**: a key added without a thought about whose it is would
        // silently join `Rules`, the group that means *true of anything*. Being wrong there is more
        // damaging than being wrong in `Display`.
        foreach ([null, SimpleType::Int, SimpleType::Decimal, SimpleType::Text] as $subject) {
            foreach (SettingKey::cases() as $key) {
                self::assertInstanceOf(SettingCategory::class, SettingCategory::of($key, $subject), $key->value);
            }
        }
    }

    #[Test]
    public function the_same_key_belongs_to_the_integer_on_one_node_and_the_double_on_another(): void
    {
        // ⚠️ **This is the owner's principle in one assertion**: *settings for `int` category integer,
        // settings for `double` category double.* The category is asked of the key **and the
        // subject**, never of the key alone — `range_min` borrows its type from whatever is being
        // configured, and the borrowing is what makes it belong.
        foreach ([SettingKey::RangeMin, SettingKey::RangeMax, SettingKey::RangeStep, SettingKey::DefaultValue] as $key) {
            self::assertSame(SettingCategory::OfTheType, SettingCategory::of($key, SimpleType::Int), $key->value);

            self::assertSame('int', SettingCategory::of($key, SimpleType::Int)->label(SimpleType::Int), $key->value);
            self::assertSame('decimal', SettingCategory::of($key, SimpleType::Decimal)->label(SimpleType::Decimal), $key->value);
        }
    }

    #[Test]
    public function a_borrowing_key_on_a_subject_with_no_type_has_no_type_to_belong_to(): void
    {
        // ⚠️ Honest rather than convenient: there is no group to name, so it falls to the rules
        // instead of inventing one. *The panel does not draw it either — a borrowing key on a subject
        // with no type has no shape to be drawn in (D-354) — so this is about where it would sit.*
        self::assertSame(SettingCategory::Rules, SettingCategory::of(SettingKey::RangeMin, null));
    }

    #[Test]
    public function the_three_that_say_how_it_is_handled_are_display(): void
    {
        // ⚠️ The owner named these three apart himself: *the **renderer** says how it is shown, the
        // **converter** says convert the output, the **validator** checks whether the input is
        // correct — so each has its own job.* `icon` joins them: it marks, it does not constrain.
        $drawing = [SettingKey::Renderer, SettingKey::Converter, SettingKey::Validator, SettingKey::Icon];

        foreach ($drawing as $key) {
            self::assertSame(SettingCategory::Display, SettingCategory::of($key, SimpleType::Int), $key->value);
        }

        foreach (SettingKey::cases() as $key) {
            if (in_array($key, $drawing, true)) {
                continue;
            }

            self::assertNotSame(SettingCategory::Display, SettingCategory::of($key, SimpleType::Int), $key->value);
        }
    }

    #[Test]
    public function the_step_belongs_to_the_type_and_not_to_the_renderer(): void
    {
        // ⚠️ **It moved, and the move is the whole point of the owner's third question.** The first
        // test was *does it change what the model permits* — by that, a step is presentation, since
        // R17 says *nothing validates it*. The second test is *whose is it* — and a step is the
        // **integer's**, right beside its min and max, which is how R17 names all three in one
        // breath. *The better question wins.*
        self::assertSame(SettingCategory::OfTheType, SettingCategory::of(SettingKey::RangeStep, SimpleType::Int));
    }

    #[Test]
    public function what_is_true_of_anything_is_a_rule(): void
    {
        // ⚠️ A thing can be required, hidden, fixed, counted or ordered whatever it holds — none of
        // these borrows a type, so none of them belongs to one.
        $rules = [
            SettingKey::ReadOnly,
            SettingKey::Multiplicity,
            SettingKey::Persistent,
        ];

        foreach ($rules as $key) {
            self::assertSame(SettingCategory::Rules, SettingCategory::of($key, SimpleType::Int), $key->value);
        }
    }

    #[Test]
    public function a_unit_conversion_belongs_to_a_unit_and_that_is_the_open_remainder(): void
    {
        // ⚠️ **The honest gap rather than a hiding place.** `factor` and `offset` belong to a **unit**
        // — a node under `Constants`, not a simple type — so nothing here can place them by type and
        // they fall to the rules. *A text node is therefore still offered `factor`, which is the part
        // of OQ-093 this does not answer: a key that belongs to a **branch**.*
        self::assertSame(SettingCategory::Rules, SettingCategory::of(SettingKey::Factor, SimpleType::Int));
        self::assertSame(SettingCategory::Rules, SettingCategory::of(SettingKey::Offset, SimpleType::Int));
    }

    #[Test]
    public function somebody_elses_key_is_taken_for_a_drawing_instruction(): void
    {
        // ⚠️ **The safer of the two guesses.** D-364 settles what free keys are for — *`cols` and
        // `rows` … it is how one renderer draws* — and who writes them. Filing an unknown key under
        // the rules would claim it constrains something.
        self::assertSame(SettingCategory::Display, SettingCategory::ofFreeKey());
    }

    #[Test]
    public function developer_mode_is_no_longer_a_setting_at_all(): void
    {
        // ⚠️ The owner: *develop is not a setting on the node but a setting in the WordPress admin
        // settings menu* (D-389). A posture is a fact about the **installation**, and putting it on
        // the settings chain meant it could differ per branch, which is meaningless. It closed
        // OQ-039, and the `Internal` category it had lived in went with it.
        self::assertNull(SettingKey::tryFrom('developer'));

        foreach (SettingCategory::cases() as $category) {
            self::assertNotSame('internal', $category->value);
        }
    }
}
