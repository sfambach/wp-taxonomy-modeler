<?php declare(strict_types=1);

/**
 * D-1540 against a real database: the door reads the conversion of a prefix from its settings, so «0,1 kΩ» and «100 Ω» carry
 * one key, and a unit without a conversion stays in the key.
 *
 *     php scripts/dev/same-key-values-check.php [path/to/wordpress]
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\RelationRecord;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\KeyValues;
use Taxmod\WordPress\ModelDoor;
use Taxmod\WordPress\Persistence\UnitScaffold;

$fehler = 0;
$pruefe = static function (bool $gut, string $was) use (&$fehler): void {
    printf("  %s %s\n", $gut ? 'ok  ' : 'FAIL', $was);
    $fehler += $gut ? 0 : 1;
};

$kilo  = UnitScaffold::nodeId('kilo');
$milli = UnitScaffold::nodeId('milli');
$ohm   = UnitScaffold::nodeId('Ohm');
$pruefe($kilo !== null && $milli !== null && $ohm !== null, 'the scaffold names kilo, milli and Ohm');

$door        = new ModelDoor();
$conversions = new ReflectionMethod(ModelDoor::class, 'conversionsIn');
$part        = static fn (int $id, string $number, ?int $prefix): array => array_values(array_filter([
    new RelationRecord($id, 1, '', TypedValue::ofDecimal($number)),
    $prefix === null ? null : new RelationRecord($id, 2, '', TypedValue::ofReference($prefix)),
    new RelationRecord($id, 3, '', TypedValue::ofReference((int) $ohm)),
]));
$parts = [101 => $part(101, '100', null), 102 => $part(102, '0.1', $kilo), 103 => $part(103, '100000', $milli), 104 => $part(104, '100', $kilo)];
$read  = $conversions->invoke($door, $parts);

$pruefe(KeyValues::normal((string) ($read[$kilo]['factor'] ?? '')) === '1000', 'kilo is read with the factor 1000');
$pruefe(KeyValues::normal((string) ($read[$milli]['factor'] ?? '')) === '0.001', 'milli is read with the factor 0.001');
$pruefe(! isset($read[$ohm]), 'Ohm carries no conversion and stays in the key');

$record = static fn (int $id, int $part): array => [new RelationRecord($id, 10, '', TypedValue::ofRecordReference($part))];
$keys   = new KeyValues([1 => [10], 2 => [10], 3 => [10], 4 => [10]], [1 => $record(1, 101), 2 => $record(2, 102), 3 => $record(3, 103), 4 => $record(4, 104)], $parts, $read);

$pruefe($keys->matching([1 => [2, 3, 4]]) === [1 => [2, 3]], '100 Ω = 0,1 kΩ = 100000 mΩ, and 100 kΩ is not');
$pruefe($door->sameKeyValuesOf([]) === [], 'no candidates, no read and no match');

echo $fehler === 0 ? "same-key-values-check: all green\n" : "same-key-values-check: {$fehler} failed\n";
exit($fehler === 0 ? 0 : 1);
