<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Printing\LabelPrefix;
use Taxmod\Core\Printing\ScannedCode;

/**
 * What a scanner types is read back into the label code (D-1032, D-1206), keyboard layout and Enter included.
 */
final class ScannedCodeTest extends TestCase
{
    /** @return array<string, array{string, ?LabelPrefix, int}> */
    public static function readable(): array
    {
        return [
            'material'                 => ['M-48284', LabelPrefix::Material, 48284],
            'place'                    => ['O-43949', LabelPrefix::Place, 43949],
            'box'                      => ['K-12', LabelPrefix::Box, 12],
            'copy'                     => ['E-7', LabelPrefix::Copy, 7],
            'lot'                      => ['P-50573', LabelPrefix::Lot, 50573],
            'small letter'             => ['m-5', LabelPrefix::Material, 5],
            'German layout, US scanner' => ['Mß48284', LabelPrefix::Material, 48284],
            'question mark'            => ['O?3', LabelPrefix::Place, 3],
            'no separator'             => ['K99', LabelPrefix::Box, 99],
            'Enter and spaces'         => ["  M-1\r\n", LabelPrefix::Material, 1],
            'bare id'                  => ['48284', null, 48284],
            'leading zeros'            => ['M-0042', LabelPrefix::Material, 42],
            'record link'              => ['https://example.org/wp-admin/admin.php?page=taxmod&taxmod_open_record=31#x', null, 31],
        ];
    }

    #[Test]
    #[DataProvider('readable')]
    public function aScanIsReadBack(string $typed, ?LabelPrefix $kind, int $record): void
    {
        $code = ScannedCode::read($typed);

        self::assertNotNull($code);
        self::assertSame($kind, $code->kind);
        self::assertSame($record, $code->record);
    }

    /** @return array<string, array{string}> */
    public static function unreadable(): array
    {
        return [
            'empty'          => [''],
            'unknown letter' => ['X-12'],
            'zero'           => ['M-0'],
            'two letters'    => ['MM-12'],
            'text'           => ['hello'],
            'two separators' => ['M--12'],
        ];
    }

    #[Test]
    #[DataProvider('unreadable')]
    public function anythingElseIsNoCode(string $typed): void
    {
        self::assertNull(ScannedCode::read($typed));
    }

    #[Test]
    public function theCodeIsWrittenAsTheLabelPrintsIt(): void
    {
        self::assertSame('M-48284', ScannedCode::read('mß48284')?->code());
        self::assertSame('31', ScannedCode::read('31')?->code());
    }

    #[Test]
    public function every_prefix_makes_a_label_code(): void
    {
        // D-1223, his word 2026-11-08 «Die Vorschau lässt sich nicht zeichnen» on P-53221: the lot's letter was refused.
        foreach (\Taxmod\Core\Printing\LabelPrefix::cases() as $prefix) {
            self::assertTrue(\Taxmod\Core\Printing\LabelPrefix::isCode($prefix->code(53221)), $prefix->value);
        }
        self::assertFalse(\Taxmod\Core\Printing\LabelPrefix::isCode('X-1'));
        self::assertFalse(\Taxmod\Core\Printing\LabelPrefix::isCode('P-'));
    }
}
