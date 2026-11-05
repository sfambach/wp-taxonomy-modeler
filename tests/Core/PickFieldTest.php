<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Renderer\DialogMarkup;
use Taxmod\Core\Renderer\SummaryRenderer;

/** D-1163: a single choice is a greyed field with the tree button beside it, both opening the dialog. */
final class PickFieldTest extends TestCase
{
    #[Test]
    public function a_chooser_with_a_tree_word_gets_the_field_and_the_tree_button(): void
    {
        $html = DialogMarkup::of('sw', 'Kiste groß', 'Kiste groß', '<span>tree</span>', '', 'taxmod-record-dialog-open', ok: 'OK', cancel: 'Abbrechen', tree: 'Baum');

        self::assertStringStartsWith('<span class="taxmod-chooser taxmod-pick">', $html);
        self::assertMatchesRegularExpression('~<label class="taxmod-record-dialog-open" for="sw">Kiste groß</label><label class="button taxmod-icon-button taxmod-pick-tree" for="sw" title="Baum">~', $html);
    }

    #[Test]
    public function a_dialog_without_a_tree_word_stays_as_it_was(): void
    {
        $html = DialogMarkup::of('sw', 'x', 'x', '', '');

        self::assertStringStartsWith('<span class="taxmod-chooser">', $html);
        self::assertStringNotContainsString('taxmod-pick-tree', $html);
    }

    #[Test]
    public function a_module_gets_the_same_record_choice_with_tree_and_new_here(): void
    {
        // D-1182, his word 2026-11-05: «müsste tree chooser sein mehr als eine ebene und man müsste neu eingeben können».
        $baum = [
            ['depth' => 0, 'name' => 'Firmen', 'records' => [], 'node' => 10, 'newWord' => 'New here …', 'createWord' => 'Create'],
            ['depth' => 1, 'name' => 'Lieferant', 'records' => [7 => 'Reichelt'], 'node' => 11, 'newWord' => 'New here …', 'createWord' => 'Create'],
            ['depth' => 1, 'name' => 'Hersteller', 'records' => [8 => 'ASUS'], 'node' => 12, 'newWord' => 'New here …', 'createWord' => 'Create'],
        ];
        $html = SummaryRenderer::pick('sources[0][firm]', '', $baum, 8, ['tree' => 'Baum', 'ok' => 'OK']);

        self::assertStringContainsString('taxmod-record-pick taxmod-record-taken', $html);
        self::assertStringContainsString('>ASUS</label><label class="button taxmod-icon-button taxmod-pick-tree"', $html);
        self::assertMatchesRegularExpression('~<input type="radio" name="sources\[0\]\[firm\]" value="8" checked>~', $html);
        self::assertStringContainsString('taxmod-record-treetoggle', $html, 'more than one level: the tree');
        self::assertSame(3, substr_count($html, 'class="taxmod-record-new"'));
        self::assertStringContainsString('data-taxmod-search="asus hersteller"', $html);
    }
}
