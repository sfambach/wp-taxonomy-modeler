<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\RecordStamp;

/**
 * What a form carries from read to save ([D-1148](../../docs/NewConcept/90-decision-log.md)).
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class RecordStampTest extends TestCase
{
    #[Test]
    public function the_text_of_a_form_field_reads_back_the_same(): void
    {
        $stamp = new RecordStamp([12 => 3, 40 => 1]);

        self::assertSame('12:3,40:1', $stamp->text());
        self::assertSame([12 => 3, 40 => 1], RecordStamp::parse($stamp->text())->versions);
    }

    #[Test]
    public function rubbish_in_the_field_is_ignored_not_guessed(): void
    {
        self::assertSame([7 => 2], RecordStamp::parse('7:2, x:1, 0:4, 9:')->versions);
        self::assertTrue(RecordStamp::parse('')->isEmpty());
    }

    #[Test]
    public function a_change_list_names_its_expectations_as_record_and_version(): void
    {
        self::assertSame([5 => 9], RecordStamp::fromList([['record' => 5, 'version' => 9], ['record' => 0, 'version' => 1], ['record' => 6]])->versions);
    }

    #[Test]
    public function only_what_moved_on_or_is_gone_is_named(): void
    {
        $stamp = new RecordStamp([1 => 1, 2 => 4, 3 => 2]);

        self::assertSame([2 => 5, 3 => null], $stamp->changedAgainst([1 => 1, 2 => 5]));
        self::assertSame([], $stamp->changedAgainst([1 => 1, 2 => 4, 3 => 2]));
    }
}
