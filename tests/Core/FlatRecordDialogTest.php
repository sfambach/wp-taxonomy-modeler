<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Renderer\SummaryRenderer;

/** D-1137: a record dialog over one node shows no node tree and no switch for it. */
final class FlatRecordDialogTest extends TestCase
{
    #[Test]
    public function a_flat_choice_hides_the_tree(): void
    {
        $knopf = static fn (string $wert, bool $an, string $wort): string => '<label>' . $wort . '</label>';
        $flach = SummaryRenderer::browserBody([['depth' => 0, 'name' => 'Projekte', 'records' => [1 => 'Voltage Blaster']]], $knopf, null, '', ['tree' => 'Baum']);
        $baum  = SummaryRenderer::browserBody([['depth' => 0, 'name' => 'Lager', 'records' => []], ['depth' => 1, 'name' => 'Bauteile', 'records' => [2 => 'R']]], $knopf, null, '', ['tree' => 'Baum']);

        self::assertStringContainsString('taxmod-record-notree', $flach);
        self::assertStringNotContainsString('taxmod-record-treetoggle', $flach);
        self::assertStringNotContainsString('taxmod-record-notree', $baum);
        self::assertStringContainsString('taxmod-record-treetoggle', $baum);
    }
}
