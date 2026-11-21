<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Service\SettingsUsage;

final class SettingsUsageTest extends TestCase
{
    #[Test]
    public function groups_become_one_row_per_attribute_with_the_field_heavy_first(): void
    {
        $rows = (new SettingsUsage())->summary([
            ['attribute' => 'renderer', 'carrier' => 'node', 'active' => true, 'count' => 40],
            ['attribute' => 'renderer', 'carrier' => 'field', 'active' => true, 'count' => 7],
            ['attribute' => 'renderer', 'carrier' => 'field', 'active' => false, 'count' => 2],
            ['attribute' => 'max', 'carrier' => 'object', 'active' => true, 'count' => 3],
            ['attribute' => 'dialog', 'carrier' => 'field', 'active' => true, 'count' => 7],
        ]);

        self::assertSame(['dialog', 'renderer', 'max'], array_column($rows, 'attribute'));
        self::assertSame(['attribute' => 'renderer', 'node' => 40, 'field' => 7, 'object' => 0, 'inactive' => 2, 'total' => 49], $rows[1]);
    }
}
