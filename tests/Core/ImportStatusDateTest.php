<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Import\StatusDate;

/**
 * A status step carries a true date or none (D-1350): an order export filed today did not arrive today.
 */
final class ImportStatusDateTest extends TestCase
{
    #[Test]
    public function the_date_the_message_names_comes_first(): void
    {
        self::assertSame('2024-03-02', StatusDate::of('2024-03-02', '2024-03-05 10:00:00', '2026-11-13 17:00:00', false));
        self::assertSame('2024-03-02', StatusDate::of('2024-03-02', null, '2026-11-13 17:00:00', true));
    }

    #[Test]
    public function then_when_the_source_received_it(): void
    {
        self::assertSame('2024-03-05', StatusDate::of(null, '2024-03-05 10:00:00', '2026-11-13 17:00:00', false));
        self::assertSame('2024-03-05', StatusDate::of(null, '2024-03-05 10:00:00', '2026-11-13 17:00:00', true));
    }

    #[Test]
    public function a_live_source_without_date_means_the_day_it_was_filed(): void
    {
        self::assertSame('2026-11-13', StatusDate::of(null, null, '2026-11-13 17:00:00', false));
    }

    #[Test]
    public function an_archive_without_date_leaves_the_field_empty(): void
    {
        self::assertNull(StatusDate::of(null, null, '2026-11-13 17:00:00', true));
        self::assertNull(StatusDate::of('', '', '2026-11-13 17:00:00', true));
    }
}
