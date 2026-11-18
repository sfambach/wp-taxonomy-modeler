<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Description\RecordChoice;
use Taxmod\Core\Renderer\SummaryRenderer;
use Taxmod\WordPress\Draw\EdgeDrawer;

/** D-1171: a record dialog offers a field to create a missing record at the node of each group, and only where creation is allowed. */
final class RecordDialogCreateTest extends TestCase
{
    #[Test]
    public function each_group_that_may_create_carries_a_nameless_field_for_its_node(): void
    {
        $knopf = static fn (string $wert, bool $an, string $wort): RecordChoice => RecordChoice::inTemplate($wert, $wort);
        $zeichne = static fn (\Taxmod\Core\Description\Describes $d): string => (new EdgeDrawer())->draw($d);
        $mit   = $zeichne(SummaryRenderer::browserBody([['depth' => 0, 'name' => 'Bauteil-Serien', 'records' => [1 => 'Molex 8981'], 'node' => 77, 'newWord' => 'Neu hier …', 'createWord' => 'Anlegen']], $knopf, null, [], []));
        $ohne  = $zeichne(SummaryRenderer::browserBody([['depth' => 0, 'name' => 'Bauteil-Serien', 'records' => [1 => 'Molex 8981'], 'node' => 77]], $knopf, null, [], []));

        self::assertStringContainsString('class="taxmod-record-new" data-taxmod-node="77"', $mit);
        self::assertStringContainsString('placeholder="Neu hier …"', $mit);
        self::assertStringContainsString('>Anlegen</button>', $mit);
        // *Without a name the field is never part of the record form.*
        self::assertDoesNotMatchRegularExpression('/taxmod-record-new-name"[^>]*\sname=/', $mit);
        self::assertStringNotContainsString('taxmod-record-new', $ohne);
    }
}
