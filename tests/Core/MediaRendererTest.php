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

    /** ⚠️ *Sein Wort: «das folder symbol oder datei symbol für den knopf verwenden und den knopf nach rechts».* */
    #[Test]
    public function the_upload_is_a_file_symbol_right_of_the_field_with_its_word_as_tooltip(): void
    {
        $markup = (new MediaRenderer())->render(
            Node::create(1, 'Projekt', null),
            new RenderContext(
                purpose: Purpose::Edit,
                value: TypedValue::ofText('https://example.org/a/plan.pdf'),
                editable: true,
                fieldName: 'taxmod_value[7][9]',
                type: SimpleType::Media,
                surroundings: new Surroundings(dialogWords: ['upload' => 'Datei hochladen']),
            )
        )->markup;

        $feld  = strpos($markup, 'class="taxmod-media-link"');
        $knopf = strpos($markup, 'taxmod-media-pick');

        self::assertNotFalse($feld);
        self::assertNotFalse($knopf);
        self::assertGreaterThan($feld, $knopf, 'der Knopf steht rechts vom Feld');
        self::assertMatchesRegularExpression('/<label class="button taxmod-icon-button taxmod-media-pick"[^>]*title="Datei hochladen"[^>]*><span[^>]*dashicons-media-default[^>]*><\/span><input type="file"[^>]*name="taxmod_value_upload\[7\]\[9\]"/', $markup);
        self::assertStringContainsString('>plan (PDF)</a>', $markup);
    }

    /** ⚠️ *Sein Wort: «knöpfe grundsätzlich ein icon verwenden wenn es eins gibt und rechts vom feld. Tooltip knopf beschreibung/name».* */
    #[Test]
    public function an_icon_button_shows_its_name_as_tooltip_and_a_worded_one_needs_none(): void
    {
        self::assertStringContainsString('title="Delete"', ControlMarkup::button(new Control('do', 'trash', 'Delete', icon: 'trash')));
        self::assertStringNotContainsString('title=', ControlMarkup::button(new Control('do', 'add', 'Add')));
    }
}
