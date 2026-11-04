<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Renderer\DialogMarkup;

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
}
