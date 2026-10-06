<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\ResidueEntry;
use Taxmod\Core\Renderer\ResidueGroup;
use Taxmod\Core\Renderer\ResidueRenderer;
use Taxmod\Core\Renderer\Submission;

/**
 * The repair surface's list: one act per line, and «nothing here» as a sentence.
 *
 * @see docs/NewConcept/20-interaction.md
 */
final class ResidueRendererTest extends TestCase
{
    private ResidueRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new ResidueRenderer();
    }

    private function entry(string $what, string $act, int $id): ResidueEntry
    {
        return new ResidueEntry(
            $what,
            new Control('do', $act, 'Remove', 'Remove it for good', true, true, 'trash'),
            new Submission('https://example.test/admin-post.php', [
                'action'        => 'taxmod_cleanup',
                'taxmod_target' => (string) $id,
                '_taxmod_nonce' => 'abc123',
            ])
        );
    }

    #[Test]
    public function an_empty_source_reads_as_good_news_rather_than_as_an_empty_list(): void
    {
        // D-247 shows residue so it can be removed deliberately; most of the time there is none,
        // and an empty table says «this screen is broken» where a sentence says «nothing here».
        $markup = $this->renderer->render([
            new ResidueGroup('Settings whose owner is gone', 'why it happens', 'Nothing to tidy up here.'),
        ]);

        self::assertStringContainsString('Nothing to tidy up here.', $markup);
        self::assertStringNotContainsString('<ul', $markup);
    }

    #[Test]
    public function every_line_carries_its_own_form_its_own_target_and_its_own_nonce(): void
    {
        // Never automatically (D-247): one form per line, so there is no «tidy everything» button
        // one Enter away.
        $markup = $this->renderer->render([
            new ResidueGroup('gone owners', 'why', 'nothing', [
                $this->entry('Owner 41 is gone and still holds 2 settings.', 'forget_settings', 41),
                $this->entry('Owner 42 is gone and still holds 1 setting.', 'forget_settings', 42),
            ]),
        ]);

        self::assertSame(2, substr_count($markup, '<form'));
        self::assertSame(2, substr_count($markup, 'name="_taxmod_nonce"'));
        self::assertStringContainsString('name="taxmod_target" value="41"', $markup);
        self::assertStringContainsString('name="taxmod_target" value="42"', $markup);
        self::assertStringContainsString('name="do" value="forget_settings"', $markup);
    }

    #[Test]
    public function the_words_arrive_translated_and_are_escaped_here(): void
    {
        // AR-2 keeps the words at the boundary; CD-1 keeps `esc_html()` out of the core, so the
        // escaping is RenderResult's.
        $markup = $this->renderer->render([
            new ResidueGroup('<b>title</b>', '<b>why</b>', 'nothing', [
                $this->entry('«Resistor & Co» — node 7', 'purge_node', 7),
            ]),
        ]);

        self::assertStringContainsString('&lt;b&gt;title&lt;/b&gt;', $markup);
        self::assertStringContainsString('Resistor &amp; Co', $markup);
        self::assertStringNotContainsString('<b>', $markup);
    }

    #[Test]
    public function an_act_that_takes_something_away_is_marked_as_one(): void
    {
        // `destroys` is a fact about the act and the red is the renderer's — so the one control
        // that must never be clicked by accident looks the same on every surface.
        $markup = $this->renderer->render([
            new ResidueGroup('t', 'w', 'nothing', [$this->entry('one', 'purge_node', 9)]),
        ]);

        self::assertStringContainsString('#b32d2e', $markup);
    }

    #[Test]
    public function each_source_keeps_its_own_block_in_the_order_it_was_handed_in(): void
    {
        $markup = $this->renderer->render([
            new ResidueGroup('first', 'w', 'nothing'),
            new ResidueGroup('second', 'w', 'nothing'),
            new ResidueGroup('third', 'w', 'nothing'),
        ]);

        self::assertSame(3, substr_count($markup, '<h2>'));
        self::assertLessThan(strpos($markup, 'second'), strpos($markup, 'first'));
        self::assertLessThan(strpos($markup, 'third'), strpos($markup, 'second'));
    }
}
