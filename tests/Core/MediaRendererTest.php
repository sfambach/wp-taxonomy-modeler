<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\ControlMarkup;
use Taxmod\Core\Renderer\MediaRenderer;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\Surroundings;

/**
 * Das Medienfeld mit Dateiknopf und Beschreibung (D-846) und der Tooltip der Symbolknöpfe (D-847).
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class MediaRendererTest extends TestCase
{
    /** ⚠️ *Sein Wort: «media sollte eine beschreibung haben, kann aus der media datei generiert werden».* */
    #[Test]
    public function a_media_value_is_described_by_its_file(): void
    {
        self::assertSame('schaltplan v2 final (PDF)', MediaRenderer::describe('https://example.org/wp-content/uploads/2026/09/schaltplan_v2-final.pdf'));
        self::assertSame('github.com/sfambach/diskbuddy64', MediaRenderer::describe('https://github.com/sfambach/diskbuddy64'), 'ohne Datei: Rechner und Weg');
        self::assertSame('github.com', MediaRenderer::describe('https://www.github.com/'));
        self::assertSame('google.de', MediaRenderer::describe('www.google.de'), 'ohne https ist es trotzdem eine Adresse');
        self::assertSame('github.com/sfambach/x', MediaRenderer::describe('github.com/sfambach/x'));
        self::assertSame('foto platine (JPG)', MediaRenderer::describe('foto%20platine.JPG'));
    }

    /** ⚠️ *D-856, sein Wort: «ja die beschriftung soll der link sein» — ohne Beschriftung bleibt die aus der Datei gerechnete.* */
    #[Test]
    public function the_caption_is_the_link_text_and_without_one_the_file_speaks(): void
    {
        self::assertStringContainsString('>Schaltplan Rev. B</a>', MediaRenderer::link('https://example.org/plan_b.pdf', 'Schaltplan Rev. B'));
        self::assertStringContainsString('>plan b (PDF)</a>', MediaRenderer::link('https://example.org/plan_b.pdf', '  '));
        self::assertStringContainsString('href="https://www.google.de"', MediaRenderer::link('www.google.de'));
    }

    /** ⚠️ *Sein Wort: «das folder symbol oder datei symbol für den knopf verwenden und den knopf nach rechts».* */
    #[Test]
    public function the_media_library_and_the_link_dialog_are_symbols_right_of_the_field(): void
    {
        // ⚠️ *Seit D-858 öffnet das Dateisymbol die Mediathek; daneben der Linkdialog (D-857). Beide nennen das Feld, das sie füllen.*
        $markup = (new MediaRenderer())->render(
            Node::create(1, 'Projekt', null),
            new RenderContext(
                purpose: Purpose::Edit,
                value: TypedValue::ofText('https://example.org/a/plan.pdf'),
                editable: true,
                fieldName: 'taxmod_value[7][9]',
                type: SimpleType::Media,
                surroundings: new Surroundings(dialogWords: ['upload' => 'Aus der Mediathek', 'link' => 'Link wählen']),
            )
        )->markup;

        $feld    = strpos($markup, 'class="taxmod-media-link"');
        $mediath = strpos($markup, 'taxmod-media-library');
        $link    = strpos($markup, 'taxmod-media-wplink');

        self::assertNotFalse($feld);
        self::assertNotFalse($mediath);
        self::assertGreaterThan($feld, $mediath, 'die Knöpfe stehen rechts vom Feld');
        self::assertGreaterThan($mediath, (int) $link, 'erst die Mediathek, dann der Link');
        self::assertMatchesRegularExpression('/taxmod-media-library"[^>]*title="Aus der Mediathek"[^>]*data-taxmod-address="taxmod_value\[7\]\[9\]"[^>]*data-taxmod-newtab="1"/', $markup);
        self::assertStringNotContainsString('type="file"', $markup, 'kein eigenes Hochladen mehr — das macht die Mediathek');
        self::assertStringContainsString('target="_blank"', $markup, 'Vorgabe: neuer Tab');
        self::assertStringContainsString('>plan (PDF)</a>', $markup);
    }

    /** ⚠️ *D-858: «open link in a new tab sollte default sein, nimm das mal in die einstellungen auf» — ausgeschaltet öffnet er im selben Tab.* */
    #[Test]
    public function without_new_tab_the_link_opens_in_place(): void
    {
        self::assertStringNotContainsString('target=', MediaRenderer::link('https://example.org/a', 'A', false));
        self::assertStringContainsString('target="_blank"', MediaRenderer::link('https://example.org/a', 'A'));
    }

    /** ⚠️ *Sein Wort: «knöpfe grundsätzlich ein icon verwenden wenn es eins gibt und rechts vom feld. Tooltip knopf beschreibung/name».* */
    #[Test]
    public function an_icon_button_shows_its_name_as_tooltip_and_a_worded_one_needs_none(): void
    {
        self::assertStringContainsString('title="Delete"', ControlMarkup::button(new Control('do', 'trash', 'Delete', icon: 'trash')));
        self::assertStringNotContainsString('title=', ControlMarkup::button(new Control('do', 'add', 'Add')));
    }
}
