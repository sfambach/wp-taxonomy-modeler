<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\WordPress\Draw\GridMarkup;

/** The one read-only table: head escaped, cells as given, numbers aligned by class, never by style. */
final class GridMarkupTest extends TestCase
{
    #[Test]
    public function it_escapes_the_head_and_keeps_the_cells(): void
    {
        $html = GridMarkup::table([['A & B'], ['Menge', GridMarkup::NUMBER]], [['<a href="#">x</a>', '3']]);

        $this->assertStringContainsString('<th scope="col">A &amp; B</th><th scope="col" class="is-number">Menge</th>', $html);
        $this->assertStringContainsString('<td><a href="#">x</a></td><td class="is-number">3</td>', $html);
        $this->assertStringNotContainsString('style=', $html);
    }

    #[Test]
    public function no_rows_say_so_across_all_columns(): void
    {
        $html = GridMarkup::table([['A'], ['B']], [], 'Nichts <da>');

        $this->assertStringContainsString('<td colspan="2">Nichts &lt;da&gt;</td>', $html);
    }

    #[Test]
    public function without_columns_there_is_no_head(): void
    {
        $this->assertStringNotContainsString('<thead>', GridMarkup::table([], [['a', 'b']], '', 'facts'));
        $this->assertStringContainsString('taxmod-grid facts', GridMarkup::table([], [], '', 'facts'));
    }
}
