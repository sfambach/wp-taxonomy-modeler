<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\Amounts;

/**
 * Ein Einheitenwert-Teil liefert seine Zahl in der Basiseinheit; eine alte Zahl liefert nichts (Feldkatalog 9/10/12).
 */
final class AmountsTest extends TestCase
{
    private const BREITE = 11;
    private const ROLLE  = 12;
    private const NUMBER = 21;
    private const PREFIX = 22;
    private const UNIT   = 23;
    private const MILLI  = 900;
    private const METER  = 910;
    private const CELSIUS = 911;

    private const CONVERSIONS = [
        self::MILLI   => ['factor' => '0.001000000000000000000000000000', 'offset' => '0'],
        self::CELSIUS => ['factor' => '1', 'offset' => '273.15'],
    ];

    /** @return list<RelationRecord> */
    private static function part(int $id, string $number, ?int $prefix, int $unit): array
    {
        return array_values(array_filter([
            new RelationRecord($id, self::NUMBER, '', TypedValue::ofDecimal($number)),
            $prefix === null ? null : new RelationRecord($id, self::PREFIX, '', TypedValue::ofReference($prefix)),
            new RelationRecord($id, self::UNIT, '', TypedValue::ofReference($unit)),
        ]));
    }

    #[Test]
    public function a_unit_value_comes_in_its_base_unit_and_an_old_number_not_at_all(): void
    {
        $amounts = new Amounts(
            [
                1 => [new RelationRecord(1, self::BREITE, '', TypedValue::ofRecordReference(101)), new RelationRecord(1, self::ROLLE, '', TypedValue::ofDecimal('62'))],
                2 => [new RelationRecord(2, self::BREITE, '', TypedValue::ofRecordReference(102))],
                3 => [new RelationRecord(3, self::BREITE, '', TypedValue::ofRecordReference(103))],
            ],
            [101 => self::part(101, '62', self::MILLI, self::METER), 102 => self::part(102, '20', self::MILLI, self::CELSIUS), 103 => [new RelationRecord(103, self::UNIT, '', TypedValue::ofReference(self::METER))]],
            self::CONVERSIONS,
        );

        $out = $amounts->of([self::BREITE, self::ROLLE]);

        self::assertEqualsWithDelta(0.062, $out[1][self::BREITE], 1e-12, '62 mm are 0.062 m');
        self::assertArrayNotHasKey(self::ROLLE, $out[1], 'an old number is the module\'s own');
        self::assertEqualsWithDelta(273.17, $out[2][self::BREITE], 1e-9, 'the prefix first, then the offset');
        self::assertArrayNotHasKey(3, $out, 'a part without a number is empty');
    }
}
