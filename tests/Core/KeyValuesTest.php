<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\KeyValues;

/**
 * Ersatz nur mit gleichen Kennwerten der Kategorie (D-1540): 100 Ω = 0,1 kΩ, Hersteller zählt nicht.
 *
 * @see docs/NewConcept/90-decision-log.md D-1540
 */
final class KeyValuesTest extends TestCase
{
    private const VALUE   = 11;
    private const PACKAGE = 12;
    private const MAKER   = 13;
    private const NUMBER  = 21;
    private const PREFIX  = 22;
    private const UNIT    = 23;
    private const KILO    = 900;
    private const OHM     = 910;

    /** @param array<int, array{0: string, 1: int|null, 2: int}> $parts part → number, prefix, unit */
    private static function values(array $parts): array
    {
        $out = [];

        foreach ($parts as $part => [$number, $prefix, $unit]) {
            $out[$part] = array_values(array_filter([
                new RelationRecord($part, self::NUMBER, '', TypedValue::ofDecimal($number)),
                $prefix === null ? null : new RelationRecord($part, self::PREFIX, '', TypedValue::ofReference($prefix)),
                new RelationRecord($part, self::UNIT, '', TypedValue::ofReference($unit)),
            ]));
        }

        return $out;
    }

    /** @return list<RelationRecord> */
    private static function record(int $id, int $part, int $package, int $maker): array
    {
        return [
            new RelationRecord($id, self::VALUE, '', TypedValue::ofRecordReference($part)),
            new RelationRecord($id, self::PACKAGE, '', TypedValue::ofReference($package)),
            new RelationRecord($id, self::MAKER, '', TypedValue::ofRecordReference($maker)),
        ];
    }

    private static function keys(array $records, array $fields = [self::VALUE, self::PACKAGE]): KeyValues
    {
        $rows = [];

        foreach ($records as $id => [$part, $package, $maker]) {
            $rows[$id] = self::record($id, $part, $package, $maker);
        }

        $parts = self::values([
            101 => ['100', null, self::OHM],
            102 => ['0.1', self::KILO, self::OHM],
            103 => ['100', self::KILO, self::OHM],
            104 => ['100.000', null, self::OHM],
        ]);

        return new KeyValues(
            array_fill_keys(array_keys($records), $fields),
            $rows,
            $parts,
            [self::KILO => ['factor' => '1000.000000000000000000000000000000', 'offset' => '0']],
        );
    }

    #[Test]
    public function a_value_in_another_prefix_is_the_same_value(): void
    {
        $keys = self::keys([1 => [101, 5, 70], 2 => [102, 5, 71], 3 => [103, 5, 70], 4 => [104, 5, 72]]);

        self::assertSame([1 => [2, 4]], $keys->matching([1 => [2, 3, 4]]));
    }

    #[Test]
    public function another_package_is_no_substitute(): void
    {
        $keys = self::keys([1 => [101, 5, 70], 2 => [102, 6, 70]]);

        self::assertSame([], $keys->matching([1 => [2]]));
    }

    #[Test]
    public function a_category_naming_no_key_field_offers_nothing(): void
    {
        $keys = self::keys([1 => [101, 5, 70], 2 => [101, 5, 70]], []);

        self::assertSame([], $keys->matching([1 => [2]]));
    }

    #[Test]
    public function decimals_are_exact(): void
    {
        self::assertSame('100', KeyValues::multiply('0.1', '1000'));
        self::assertSame('0.0047', KeyValues::multiply('4.7', '0.001'));
        self::assertSame('293.15', KeyValues::add('20', '273.15'));
        self::assertSame('-253.15', KeyValues::add('20', '-273.15'));
        self::assertSame('0', KeyValues::add('-1.5', '1.50'));
        self::assertSame('12', KeyValues::normal('012.000'));
    }
}
