<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\SimpleType;

/**
 * The simple data types that ship, and where their values land.
 *
 * @see docs/NewConcept/10-domain-core.md
 */
final class SimpleTypeTest extends TestCase
{
    #[Test]
    public function every_type_lands_in_a_column_that_exists(): void
    {
        // Typed columns, never one stringly value cast in and out (D-071, D-074).
        $columns = ['value_int', 'value_decimal', 'value_text', 'value_date', 'value_ref'];

        foreach (SimpleType::cases() as $type) {
            self::assertContains($type->column(), $columns, $type->value . ' lands nowhere');
        }
    }

    #[Test]
    public function nothing_lands_in_a_floating_point_column_because_there_is_none(): void
    {
        // D-057: a price, a resistance and a tolerance are exact quantities. Binary floating
        // point cannot represent 0.1, and the error first shows up in a sum that is one cent
        // off and nobody can explain.
        self::assertSame('value_decimal', SimpleType::Decimal->column());
        self::assertSame('value_int', SimpleType::Int->column());
    }

    #[Test]
    public function a_bool_is_an_integer_not_a_column_of_its_own(): void
    {
        // D-315. And a missing row still means *not answered*, never *no*.
        self::assertSame('value_int', SimpleType::Bool->column());
    }

    #[Test]
    public function a_version_is_text_although_it_exists_for_ordering(): void
    {
        // ⚠️ D-321: it earns its place because 1.10 comes *after* 1.9. That the comparison is
        // not the column's job is the point — the ordering lives in the renderer and the
        // converter, and the column only has to keep what was written.
        self::assertSame('value_text', SimpleType::Version->column());
    }

    #[Test]
    public function a_user_reference_is_text_because_it_belongs_to_a_foreign_system(): void
    {
        // P4d: an opaque key of a foreign system is text, so the core stays ignorant of
        // WordPress (D-171). value_ref is for our own identities only.
        self::assertSame('value_text', SimpleType::UserRef->column());
        self::assertSame('value_ref', SimpleType::NodeRef->column());
    }

    #[Test]
    public function char_is_not_text_of_length_one(): void
    {
        // D-329: it shares the column and not the nature — a char has a numeric identity behind
        // it and renderings text does not have.
        self::assertNotSame(SimpleType::Char, SimpleType::Text);
        self::assertSame(SimpleType::Text->column(), SimpleType::Char->column());
    }

    #[Test]
    public function the_names_are_the_node_names_and_are_all_distinct(): void
    {
        $names = SimpleType::names();

        self::assertSame($names, array_values(array_unique($names)));
        self::assertContains('datetime', $names, 'one type for date, time and both (D-291)');
        self::assertNotContains('date', $names);
        self::assertNotContains('time', $names);
        self::assertNotContains('double', $names, 'no floating point anywhere (D-057)');
        self::assertNotContains('textarea', $names, 'a textarea is a renderer, not a type');
    }
}
