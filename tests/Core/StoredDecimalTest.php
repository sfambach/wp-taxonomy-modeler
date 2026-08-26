<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\SimpleType;
use Taxmod\WordPress\Persistence\StoredDecimal;

/**
 * Taking the `decimal(30,10)` padding off again (D-394).
 *
 * ⚠️ *A boundary class with no WordPress in it, so it is checked here where the run is fast — and
 * checked at all because it was written to fix a real read-back: `2.7 kΩ` came out of the database as
 * `2.7000000000 k Ω`.*
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class StoredDecimalTest extends TestCase
{
    #[Test]
    public function the_padding_a_decimal_column_adds_comes_off(): void
    {
        self::assertSame('2.7', StoredDecimal::read('2.7000000000'));
        self::assertSame('25.4', StoredDecimal::read('25.4000000000'));
        self::assertSame('-273.15', StoredDecimal::read('-273.1500000000'));
    }

    #[Test]
    public function zero_survives_being_written_as_ten_zeros(): void
    {
        // ⚠️ Trimming `0.0000000000` naively leaves an empty string, and `-0.5000000000` leaves `-`.
        // **Neither is a number**, and zero is a value — this is the edge the trim is written for.
        self::assertSame('0', StoredDecimal::read('0.0000000000'));
        self::assertSame('-0.5', StoredDecimal::read('-0.5000000000'));
    }

    #[Test]
    public function nothing_stays_nothing(): void
    {
        // *Nothing is nothing* (D-232): an unanswered column is null and must not become `'0'`.
        self::assertNull(StoredDecimal::read(null));
        self::assertNull(StoredDecimal::read(''));
    }

    #[Test]
    public function a_value_with_no_point_is_left_alone(): void
    {
        // Nothing to trim, and trimming zeros off `1200` would be a catastrophe.
        self::assertSame('12', StoredDecimal::read('12'));
        self::assertSame('1200', StoredDecimal::read('1200'));
    }

    #[Test]
    public function what_a_person_typed_is_never_trimmed(): void
    {
        // ⚠️ **The distinction the failing test taught me.** I first put this trim in
        // `TypedValue::ofDecimal()`, and a test caught it at once: *`2.50` must not come back as
        // `2.5`* (D-057). **What somebody typed is theirs** — only the string arriving from a
        // `decimal` column is padded, so only that string is trimmed.
        self::assertSame('2.50', SimpleType::Decimal->valueFrom('2.50')->decimal);
    }
}
