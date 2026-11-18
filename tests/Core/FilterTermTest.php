<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Listing\FilterTerm;
use Taxmod\Core\Listing\ListColumn;
use Taxmod\Core\Listing\ListView;

/** D-1462: one rule for a column filter, a number column compares numbers. The cases are the ones list-head.js runs too. */
final class FilterTermTest extends TestCase
{
    #[Test]
    public function every_shared_case_holds(): void
    {
        $cases = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/scripts/dev/lib/filter-term-cases.json'), true);

        self::assertIsArray($cases);

        foreach ($cases as [$text, $term, $number, $expected]) {
            self::assertSame($expected, FilterTerm::matches($text, $term, $number), $text . ' / ' . $term . ($number ? ' (number)' : ''));
        }
    }

    #[Test]
    public function a_number_column_keeps_exactly_the_number(): void
    {
        $view = new ListView([new ListColumn('name', 'Name'), new ListColumn('min', 'Min', number: true)], null, '', ['min' => '2']);
        $rows = [['cells' => ['name' => 'a', 'min' => '2']], ['cells' => ['name' => 'b', 'min' => '12']], ['cells' => ['name' => 'c', 'min' => '']]];

        self::assertSame(['a'], array_map(static fn (array $row): string => $row['cells']['name'], $view->apply($rows)));
    }

    #[Test]
    public function a_column_that_cannot_be_filtered_has_no_filter(): void
    {
        $view = new ListView([new ListColumn('image', 'Bild', filterable: false), new ListColumn('name', 'Name')], null, '', ['image' => 'd', 'name' => 'x']);

        self::assertSame(['name' => 'x'], $view->filters());
    }
}
