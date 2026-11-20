<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Description\InputKind;
use Taxmod\Core\Description\ValueInput;
use Taxmod\WordPress\Draw\EdgeDrawer;
use Taxmod\WordPress\Draw\SelectMarkup;

/**
 * D-1504: the page-form inputs the renderer draws in place of hand-written ones send the same keys and values as before —
 * a ticked box its own value, an unticked one nothing at all.
 */
final class PageInputTest extends TestCase
{
    #[Test]
    public function a_tick_sends_its_own_value_and_no_hidden_zero(): void
    {
        $ticked   = EdgeDrawer::control(new ValueInput(InputKind::Tick, 'quelle[3][remove]', '1', checked: true));
        $unticked = EdgeDrawer::control(new ValueInput(InputKind::Tick, 'selected[]', '12:4:7'));

        self::assertSame('<input type="checkbox" name="quelle[3][remove]" value="1" checked>', $ticked);
        self::assertSame('<input type="checkbox" name="selected[]" value="12:4:7">', $unticked);
        self::assertStringNotContainsString('hidden', $ticked . $unticked);
    }

    #[Test]
    public function a_choice_is_one_radio_per_possibility_with_the_taken_one_checked_and_locked_ones_disabled(): void
    {
        $html = EdgeDrawer::control(new ValueInput(InputKind::Choice, 'below', '0', choices: ['0' => 'hängen', '1' => 'mitlöschen'], locked: [1], lines: true));

        self::assertSame(2, substr_count($html, 'type="radio"'));
        self::assertStringContainsString('name="below" value="0" checked>', $html);
        self::assertStringContainsString('name="below" value="1" disabled>', $html);
        self::assertStringContainsString('<label style="display:block">', $html);
    }

    #[Test]
    public function a_line_names_its_suggestions(): void
    {
        self::assertStringContainsString(' list="taxmod-stock-reasons"', EdgeDrawer::control(new ValueInput(InputKind::Line, 'reason', '', suggestions: 'taxmod-stock-reasons')));
    }

    #[Test]
    public function a_locked_option_stays_visible_but_cannot_be_taken(): void
    {
        $html = SelectMarkup::of('place_type', [3 => 'Fach', 4 => 'Regal'], '4', false, '', [], true, '', [3]);

        self::assertStringContainsString('value="3" disabled>Fach', $html);
        self::assertStringContainsString('value="4" selected>Regal', $html);
    }

    #[Test]
    public function a_page_select_with_one_answer_still_sends_it(): void
    {
        $html = SelectMarkup::of('place', [17 => 'Test'], null, false, 'f1');

        self::assertStringContainsString(' disabled', $html);
        self::assertStringContainsString('<input type="hidden" name="place" form="f1" value="17">', $html);
        self::assertStringNotContainsString('type="hidden"', SelectMarkup::of('place', [17 => 'Test', 18 => 'Lager'], null, false));
    }

    #[Test]
    public function a_read_only_select_with_one_answer_sends_nothing(): void
    {
        self::assertStringNotContainsString('type="hidden"', SelectMarkup::of('place', [17 => 'Test'], '17', false, 'f1', [], false));
    }
}
