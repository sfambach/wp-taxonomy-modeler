<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Service\DailyBackupRetention;

final class DailyBackupRetentionTest extends TestCase
{
    public function testTheNewestSevenStayAndOtherCopiesAreNotTouched(): void
    {
        $names = ['vor-einspielen-20261101-010000-x.zip', 'index.php', '.htaccess'];

        for ($day = 1; $day <= 9; $day++) {
            $names[] = sprintf('taeglich-202611%02d-030000-abc.zip', $day);
        }

        self::assertSame(['taeglich-20261102-030000-abc.zip', 'taeglich-20261101-030000-abc.zip'], DailyBackupRetention::toRemove($names));
        self::assertSame('taeglich-20261109-030000-abc.zip', DailyBackupRetention::newest($names));
    }

    public function testNothingGoesWhileThereAreFewer(): void
    {
        self::assertSame([], DailyBackupRetention::toRemove(['taeglich-20261101-030000-a.zip']));
        self::assertNull(DailyBackupRetention::newest(['vor-ability-20261101-030000-a.zip']));
    }
}
