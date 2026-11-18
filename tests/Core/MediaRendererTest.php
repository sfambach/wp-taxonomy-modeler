<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\Type\MediaType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Port\MediaFile;
use Taxmod\Core\Port\MediaLibrary;
use Taxmod\Core\Renderer\Control;
use Taxmod\WordPress\Draw\ControlMarkup;
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
        self::assertStringContainsString('>Schaltplan Rev. B</a>', self::link('https://example.org/plan_b.pdf', 'Schaltplan Rev. B'));
        self::assertStringContainsString('>plan b (PDF)</a>', self::link('https://example.org/plan_b.pdf', '  '));
        self::assertStringContainsString('href="https://www.google.de"', self::link('www.google.de'));
    }

    /** ⚠️ *D-865, sein Wort: «id» — eine Datei der Mediathek steht als `media:<Id>`, der Rand sagt Adresse, Titel und Vorschaubild.* */
    #[Test]
    public function a_library_file_is_stored_by_id_and_an_image_shows_its_thumbnail(): void
    {
        $bibliothek = new class () implements MediaLibrary {
            public int $gefragt = 0;

            public function filesFor(array $ids): array
            {
                ++$this->gefragt;

                return array_intersect_key([
                    7 => new MediaFile(7, 'https://example.org/up/platine.jpg', 'Platine oben', 'https://example.org/up/platine-150x150.jpg'),
                    8 => new MediaFile(8, 'https://example.org/up/plan.pdf', 'Schaltplan'),
                    10 => new MediaFile(10, 'https://example.org/up/20260605_220411.jpg', '20260605_220411', 'https://example.org/up/t.jpg', 'Platine bestückt oben'),
                ], array_flip($ids));
            }

            public function document(string $key): ?MediaFile
            {
                return $key === str_repeat('a', 32) ? new MediaFile(0, '/wp-admin/admin-post.php?action=taxmod_document', 'Rechnung.pdf') : null;
            }

            public function postStateOf(string $address): string
            {
                return '';
            }
        };

        self::assertSame(7, MediaType::libraryIdOf(' media:7 '));
        self::assertNull(MediaType::libraryIdOf('https://example.org/media:7'));
        self::assertSame('media:7', MediaType::libraryAddress(7));

        $bild = self::link('media:7', '', true, $bibliothek);
        self::assertStringContainsString('href="https://example.org/up/platine.jpg"', $bild);
        self::assertStringContainsString('<img class="taxmod-media-thumb" src="https://example.org/up/platine-150x150.jpg" alt="Platine oben"', $bild);

        self::assertStringContainsString('>Schaltplan</a>', self::link('media:8', '', true, $bibliothek), 'kein Bild: der Titel ist der Linktext');
        self::assertStringContainsString('>Rev. B</a>', self::link('media:8', 'Rev. B', true, $bibliothek), 'die Beschriftung gewinnt');
        self::assertSame('Schaltplan', MediaRenderer::describe('media:8', $bibliothek));

        // ⚠️ *D-879, sein Wort: «bilder haben eigentlich immer eine caption/titel» — die Bildunterschrift vor dem Titel, der meist der
        // Dateiname der Kamera ist.*
        self::assertSame('Platine bestückt oben', MediaRenderer::describe('media:10', $bibliothek));
        self::assertStringContainsString('alt="Platine bestückt oben"', self::link('media:10', '', true, $bibliothek));

        // *Eine Id ohne Datei zeigt, was gespeichert ist, und ist kein Link ins Leere.*
        $fehlt = self::link('media:9', '', true, $bibliothek);
        self::assertStringContainsString('taxmod-media-missing', $fehlt);
        self::assertStringNotContainsString('href=', $fehlt);

        // *Ohne Naht bleibt die gespeicherte Adresse stehen — nichts wird erfunden.*
        self::assertStringContainsString('media:7', self::link('media:7'));
    }

    /** ⚠️ *Sein Wort: «das folder symbol oder datei symbol für den knopf verwenden und den knopf nach rechts».* */
    #[Test]
    public function the_media_library_and_the_link_dialog_are_symbols_right_of_the_field(): void
    {
        // ⚠️ *Seit D-858 öffnet das Dateisymbol die Mediathek; daneben der Linkdialog (D-857). Beide nennen das Feld, das sie füllen.*
        $markup = \Taxmod\WordPress\Draw\Markup::of((new MediaRenderer())->render(
            Node::create(1, 'Projekt', null),
            new RenderContext(
                purpose: Purpose::Edit,
                value: TypedValue::ofText('https://example.org/a/plan.pdf'),
                editable: true,
                fieldName: 'taxmod_value[7][9]',
                type: SimpleType::Media,
                surroundings: new Surroundings(dialogWords: ['upload' => 'Aus der Mediathek', 'link' => 'Link wählen']),
            )
        ));

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
        self::assertStringNotContainsString('target=', self::link('https://example.org/a', 'A', false));
        self::assertStringContainsString('target="_blank"', self::link('https://example.org/a', 'A'));
    }

    /** ⚠️ *Sein Wort: «knöpfe grundsätzlich ein icon verwenden wenn es eins gibt und rechts vom feld. Tooltip knopf beschreibung/name».* */
    #[Test]
    public function an_icon_button_shows_its_name_as_tooltip_and_a_worded_one_needs_none(): void
    {
        self::assertStringContainsString('title="Delete"', ControlMarkup::button(new Control('do', 'trash', 'Delete', icon: 'trash')));
        self::assertStringNotContainsString('title=', ControlMarkup::button(new Control('do', 'add', 'Add')));
    }

    /** ⚠️ *D-1023: eine Datei der verschlüsselten Ablage ist nur für den ein Link, der sie öffnen darf.* */
    #[Test]
    public function a_vault_file_links_only_for_who_may_open_it(): void
    {
        $bibliothek = new class () implements MediaLibrary {
            public function filesFor(array $ids): array
            {
                return [];
            }

            public function document(string $key): ?MediaFile
            {
                return match ($key) {
                    str_repeat('a', 32) => new MediaFile(0, '/download?a', 'Rechnung.pdf'),
                    str_repeat('c', 32) => new MediaFile(0, '', 'Geheim.pdf'),
                    default => null,
                };
            }

            public function postStateOf(string $address): string
            {
                return '';
            }
        };

        self::assertSame(str_repeat('b', 32), MediaType::vaultKeyOf('vault:' . str_repeat('b', 32)));
        self::assertStringContainsString('href="/download?a">Rechnung.pdf</a>', self::link('vault:' . str_repeat('a', 32), '', true, $bibliothek));
        self::assertStringNotContainsString('href', self::link('vault:' . str_repeat('b', 32), '', true, $bibliothek));
        self::assertStringNotContainsString('href', self::link('vault:' . str_repeat('a', 32)));
        self::assertSame('<span class="taxmod-media taxmod-media-private">Geheim.pdf</span>', self::link('vault:' . str_repeat('c', 32), '', true, $bibliothek));
    }

    /** ⚠️ *D-1413: ein nicht veröffentlichter eigener Beitrag sagt seinen Status neben dem Link; ein veröffentlichter nichts.* */
    #[Test]
    public function an_unpublished_post_shows_its_state_beside_the_link(): void
    {
        $bibliothek = new class () implements MediaLibrary {
            public function filesFor(array $ids): array
            {
                return [];
            }

            public function document(string $key): ?MediaFile
            {
                return null;
            }

            public function postStateOf(string $address): string
            {
                return $address === 'https://example.org/?p=12492' ? 'Entwurf' : '';
            }
        };

        self::assertStringEndsWith('</a> <span class="taxmod-media-state">Entwurf</span>', self::link('https://example.org/?p=12492', 'MIDI HAT', true, $bibliothek));
        self::assertStringEndsWith('</a>', self::link('https://example.org/hallo/', 'Hallo', true, $bibliothek));
        self::assertStringEndsWith('</a>', self::link('https://example.org/?p=12492', 'MIDI HAT'));
    }

    private static function link(string $adresse, string $beschriftung = '', bool $neuerTab = true, ?\Taxmod\Core\Port\MediaLibrary $bibliothek = null): string
    {
        return (new \Taxmod\WordPress\Draw\EdgeDrawer())->draw(MediaRenderer::linkTo($adresse, $beschriftung, $neuerTab, $bibliothek));
    }
}
