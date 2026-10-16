<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Renderer\ComparisonPosition;
use Taxmod\Core\Renderer\ComparisonTable;

/**
 * Der Vergleich stellt den Hauptsatz heraus und an seinen Platz (D-920).
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class ComparisonTableTest extends TestCase
{
    private const COLUMNS = [
        ['id' => 1, 'label' => 'Celeron D 2,8 GHz'],
        ['id' => 2, 'label' => 'Celeron D 3,2 GHz'],
        ['id' => 3, 'label' => 'Celeron D 3,33 GHz'],
    ];

    private static function ids(array $columns): array
    {
        return array_map(static fn (array $c): int => $c['id'], $columns);
    }

    #[Test]
    public function front_puts_the_main_record_first_and_keeps_the_others_in_order(): void
    {
        self::assertSame([3, 1, 2], self::ids(ComparisonTable::ordered(self::COLUMNS, 3, ComparisonPosition::Front)));
    }

    #[Test]
    public function middle_puts_the_main_record_between_the_others(): void
    {
        self::assertSame([1, 3, 2], self::ids(ComparisonTable::ordered(self::COLUMNS, 3, ComparisonPosition::Middle)));
        self::assertSame([1, 2, 3], self::ids(ComparisonTable::ordered(self::COLUMNS, 2, ComparisonPosition::Middle)));
    }

    #[Test]
    public function an_unknown_position_word_is_front(): void
    {
        self::assertSame(ComparisonPosition::Front, ComparisonPosition::fromWord('links'));
    }

    #[Test]
    public function the_main_column_is_marked_and_a_group_names_its_term_once(): void
    {
        $html = ComparisonTable::render(
            self::COLUMNS,
            2,
            ComparisonPosition::Front,
            ['Benchmark', 'Szenario'],
            [
                ['labels' => ['Doom', 'min'], 'cells' => [1 => '147', 2 => '137', 3 => '136']],
                ['labels' => ['Doom', 'max'], 'cells' => [1 => '603', 2 => '590']],
            ],
            'Im Beitrag',
            'Vergleich',
        );

        self::assertSame(1, substr_count($html, '<th class="taxmod-comparison-value taxmod-comparison-main"><span class="taxmod-comparison-role">Im Beitrag</span>Celeron D 3,2 GHz</th>'));
        self::assertSame(1, substr_count($html, '>Doom<'));
        self::assertStringContainsString('<td class="taxmod-comparison-value taxmod-comparison-other"></td></tr></tbody>', $html);
    }

    #[Test]
    public function labels_and_values_are_escaped(): void
    {
        $html = ComparisonTable::render([['id' => 1, 'label' => '<b>x</b>']], 1, ComparisonPosition::Front, [], [['labels' => ['a&b'], 'cells' => [1 => '<i>']]], 'M', 'V');

        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('a&amp;b', $html);
        self::assertStringContainsString('&lt;i&gt;', $html);
    }

    #[Test]
    public function a_grouped_table_heads_each_group_once_and_joins_the_labels(): void
    {
        $html = ComparisonTable::render(
            self::COLUMNS,
            2,
            ComparisonPosition::Middle,
            ['Szenario', 'Einheit'],
            [
                ['group' => 'Chris 3D Benchmark', 'labels' => ['', 'Punkte'], 'cells' => [2 => '1223.5']],
                ['group' => 'Chris 3D Benchmark', 'labels' => ['640x480', 'Punkte'], 'cells' => [2 => '205.2']],
                ['group' => 'Landmark', 'labels' => ['Landmark [CPU]'], 'cells' => [1 => '23400']],
            ],
            '',
            '',
        );

        self::assertSame(1, substr_count($html, '<tr class="taxmod-comparison-head"><th colspan="4">Chris 3D Benchmark</th></tr>'));
        self::assertSame(1, substr_count($html, '<th colspan="4">Landmark</th>'));
        self::assertStringContainsString('<thead><tr><th>Szenario · Einheit</th>', $html);
        self::assertStringContainsString('<td class="taxmod-comparison-detail">640x480 · Punkte</td>', $html);
        self::assertStringContainsString('<td class="taxmod-comparison-detail">Punkte</td>', $html);
    }
}
