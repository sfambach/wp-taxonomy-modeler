<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Addon\AddonSite;
use Taxmod\Core\Addon\MetricAddon;
use Taxmod\Core\Addon\ShippedAddons;
use Taxmod\Core\Tile\MonthlySeries;
use Taxmod\Core\Tile\Tile;
use Taxmod\Core\Tile\TileGrid;
use Taxmod\Core\Tile\TileTone;

/**
 * The shared tiles and the key figure per month (D-1149).
 *
 * @see docs/pakete/kacheln/konzept.md
 */
final class TileTest extends TestCase
{
    #[Test]
    public function the_window_ends_in_the_current_month_and_fills_missing_months_with_zero(): void
    {
        $series = MonthlySeries::of(['2026-09' => 4, '2026-11' => '2.5', '2025-01' => 99], '2026-11', 3);

        self::assertSame(['2026-09' => 4.0, '2026-10' => 0.0, '2026-11' => 2.5], $series->values());
        self::assertSame(2.5, $series->current());
        self::assertSame(0.0, $series->previous());
        self::assertSame(2.0, $series->averageBefore());
        self::assertSame(6.5, $series->total());
        self::assertSame(1, $series->direction());
    }

    #[Test]
    public function the_window_crosses_the_turn_of_the_year(): void
    {
        $series = MonthlySeries::of([], '2027-02', 4);

        self::assertSame(['2026-11', '2026-12', '2027-01', '2027-02'], array_keys($series->values()));
    }

    #[Test]
    public function a_single_month_has_no_previous_and_no_mean(): void
    {
        $series = MonthlySeries::of(['2026-11' => 3], '2026-11', 1);

        self::assertNull($series->previous());
        self::assertNull($series->averageBefore());
        self::assertSame(0, $series->direction());
    }

    #[Test]
    public function a_wrong_month_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MonthlySeries::of([], '11/2026', 12);
    }

    #[Test]
    public function the_grid_escapes_and_writes_its_style_once(): void
    {
        TileGrid::forgetStyle();

        $first  = TileGrid::of([new Tile('A <b>', '/x?a=1&b=2', '4', ['line "1"'])], 'Kennzahlen');
        $second = TileGrid::of([new Tile('B', '/y')]);

        self::assertStringContainsString('<style>', $first);
        self::assertStringNotContainsString('<style>', $second);
        self::assertStringContainsString('<h2 class="taxmod-tiles-heading">Kennzahlen</h2>', $first);
        self::assertStringContainsString('<a class="taxmod-tile" href="/x?a=1&amp;b=2"><span class="taxmod-tile-label">A &lt;b&gt;</span><span class="taxmod-tile-figure">4</span><span class="taxmod-tile-line">line &quot;1&quot;</span></a>', $first);
        self::assertSame('', TileGrid::of([]));
    }

    #[Test]
    public function a_tile_with_actions_is_no_link_itself_but_its_label_is(): void
    {
        $html = TileGrid::of([new Tile('Mail', '/mail', null, [], TileTone::Alert, actions: '<form></form>')]);

        self::assertStringContainsString('<div class="taxmod-tile is-alert"><a class="taxmod-tile-label" href="/mail">Mail</a>', $html);
        self::assertStringContainsString('<span class="taxmod-tile-actions"><form></form></span></div>', $html);
    }

    #[Test]
    public function bars_scale_to_the_largest_and_mark_the_current_month(): void
    {
        $html = TileGrid::of([new Tile('X', '', '2', [], TileTone::Normal, [0.0, 4.0, 2.0], ['Sep', 'Okt', 'Nov'])]);

        self::assertStringContainsString('<span class="taxmod-tile-bar" style="height:0%" title="Sep"></span>', $html);
        self::assertStringContainsString('<span class="taxmod-tile-bar" style="height:100%" title="Okt"></span>', $html);
        self::assertStringContainsString('<span class="taxmod-tile-bar is-current" style="height:50%" title="Nov"></span>', $html);
    }

    #[Test]
    public function the_key_figure_is_a_shipped_add_on_chosen_at_a_node(): void
    {
        $registry = ShippedAddons::registry();

        self::assertSame(MetricAddon::class, $registry->classFor(MetricAddon::NAME));
        self::assertSame([AddonSite::Node], (new MetricAddon())->sites());
        self::assertContains(MetricAddon::class, array_map(static fn (object $a): string => $a::class, $registry->forSites([AddonSite::Node])));
    }
}
