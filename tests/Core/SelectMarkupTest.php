<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Renderer\SelectMarkup;

/**
 * Das eine Auswahlfeld (D-848) nach der Regel aus D-380: «select fields always greyed out when there is no entry or only one entry —
 * empty-if-available counts as an entry».
 */
final class SelectMarkupTest extends TestCase
{
    #[Test]
    public function without_an_entry_it_is_disabled_and_greyed_and_so_is_its_plus(): void
    {
        $markup = SelectMarkup::of('wahl', []);

        self::assertStringContainsString(' disabled', $markup);
        self::assertStringContainsString(SelectMarkup::GREYED, $markup);
        self::assertStringContainsString(' disabled', SelectMarkup::addButton([], 'Feld'));
    }

    #[Test]
    public function one_entry_beside_nothing_is_a_real_choice(): void
    {
        $markup = SelectMarkup::of('wahl', [7 => 'Bauform']);

        self::assertStringNotContainsString(' disabled', $markup);
        self::assertStringNotContainsString(' disabled', SelectMarkup::addButton([7 => 'Bauform'], 'Feld'));
    }

    #[Test]
    public function one_entry_where_nothing_is_no_answer_is_chosen_and_greyed(): void
    {
        $markup = SelectMarkup::of('art', ['composition' => 'composition'], null, false);

        self::assertStringContainsString(' disabled', $markup);
        self::assertStringContainsString('value="composition" selected', $markup);
    }

    #[Test]
    public function every_select_carries_its_name_form_and_classes_in_one_shape(): void
    {
        $markup = SelectMarkup::of('a[b]', ['x' => 'X', 'y' => 'Y'], 'y', false, 'f1', ['class' => 'eigen', 'data-taxmod-name' => 'a[b]']);

        self::assertStringStartsWith('<select name="a[b]" form="f1" class="taxmod-choice eigen" data-taxmod-name="a[b]">', $markup);
        self::assertStringContainsString('value="y" selected', $markup);
    }
}
