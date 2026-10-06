<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\SettingCategory;
use Taxmod\Core\Model\SimpleType;

/**
 * Die Gruppe einer Einstellungszeile nach ihrem Namen — seit Schritt 7 des Bauplans (2026-09-11)
 * ohne die Aufzählung `SettingKey` ([D-712](../../docs/NewConcept/90-decision-log.md)).
 */
final class SettingCategoryTest extends TestCase
{
    #[Test]
    public function every_name_lands_somewhere_for_every_subject(): void
    {
        foreach ([null, SimpleType::Int, SimpleType::Decimal, SimpleType::Text] as $subject) {
            foreach (['min', 'max', 'step', 'renderer', 'converter', 'addons', 'display_size', 'orientation', 'irgendwas'] as $key) {
                self::assertInstanceOf(SettingCategory::class, SettingCategory::of($key, $subject), $key);
            }
        }
    }

    #[Test]
    public function the_bounds_belong_to_the_integer_on_one_node_and_the_decimal_on_another(): void
    {
        foreach (['min', 'max', 'step'] as $key) {
            self::assertSame(SettingCategory::OfTheType, SettingCategory::of($key, SimpleType::Int), $key);
            self::assertSame('int', SettingCategory::of($key, SimpleType::Int)->label(SimpleType::Int), $key);
            self::assertSame('decimal', SettingCategory::of($key, SimpleType::Decimal)->label(SimpleType::Decimal), $key);
        }
    }

    #[Test]
    public function a_bound_on_a_subject_with_no_type_has_no_type_to_belong_to(): void
    {
        self::assertSame(SettingCategory::Rules, SettingCategory::of('min', null));
    }

    #[Test]
    public function what_draws_and_converts_is_display(): void
    {
        foreach (['renderer', 'converter', 'addons', 'icon'] as $key) {
            self::assertSame(SettingCategory::Display, SettingCategory::of($key, SimpleType::Int), $key);
        }

        foreach (['display_size', 'orientation', 'with_label'] as $key) {
            self::assertSame(SettingCategory::Rules, SettingCategory::of($key, SimpleType::Int), $key);
        }
    }
}
