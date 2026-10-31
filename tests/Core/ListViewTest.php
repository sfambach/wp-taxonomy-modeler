<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Listing\ListColumn;
use Taxmod\Core\Listing\ListView;

final class ListViewTest extends TestCase
{
    /** @return list<ListColumn> */
    private function columns(): array
    {
        return [new ListColumn('nr', 'Nr.', fixed: true), new ListColumn('name', 'Material', fixed: true),
            new ListColumn('group', 'Gruppe'), new ListColumn('stock', 'Bestand'), new ListColumn('maker', 'Hersteller', default: false)];
    }

    /** @return list<array{cells: array<string, string>, sort: array<string, float>}> */
    private function rows(): array
    {
        return [
            ['cells' => ['nr' => '2', 'name' => 'NE555', 'group' => 'Timer', 'stock' => '10', 'maker' => 'TI'], 'sort' => ['stock' => 10.0]],
            ['cells' => ['nr' => '1', 'name' => 'LM2930T', 'group' => 'Regler', 'stock' => '9', 'maker' => 'ST'], 'sort' => ['stock' => 9.0]],
            ['cells' => ['nr' => '3', 'name' => 'Quarz 8 MHz', 'group' => 'Quarze', 'stock' => '100', 'maker' => 'TI'], 'sort' => ['stock' => 100.0]],
        ];
    }

    #[Test]
    public function the_starting_set_stands_until_the_user_chooses_and_fixed_columns_always_stand(): void
    {
        $keys = static fn (ListView $v): array => array_map(static fn (ListColumn $c): string => $c->key, $v->visible());

        self::assertSame(['nr', 'name', 'group', 'stock'], $keys(new ListView($this->columns())));
        self::assertSame(['nr', 'name', 'maker'], $keys(new ListView($this->columns(), ['maker'])));
        self::assertSame(['nr', 'name'], $keys(new ListView($this->columns(), [])));
        self::assertSame(['nr', 'name', 'group', 'stock'], $keys(new ListView($this->columns(), ['gone'])));
    }

    #[Test]
    public function search_looks_at_hidden_columns_too_filters_mean_contains_and_numbers_sort_as_numbers(): void
    {
        $names = static fn (array $rows): array => array_map(static fn (array $r): string => $r['cells']['name'], $rows);

        self::assertSame(['NE555', 'Quarz 8 MHz'], $names((new ListView($this->columns(), search: 'ti'))->apply($this->rows())));
        self::assertSame(['Quarz 8 MHz'], $names((new ListView($this->columns(), filters: ['group' => 'quar', 'nope' => 'x']))->apply($this->rows())));
        self::assertSame(['Quarz 8 MHz', 'NE555', 'LM2930T'], $names((new ListView($this->columns(), orderBy: 'stock', descending: true))->apply($this->rows())));
        self::assertSame(['LM2930T', 'NE555', 'Quarz 8 MHz'], $names((new ListView($this->columns(), orderBy: 'name'))->apply($this->rows())));
    }

    #[Test]
    public function a_page_out_of_range_shows_the_last_one(): void
    {
        self::assertSame([3], ListView::page([1, 2, 3], 9, 2));
        self::assertSame([1, 2], ListView::page([1, 2, 3], 0, 2));
        self::assertSame(1, ListView::clampPage(5, 0, 50));
    }
}
