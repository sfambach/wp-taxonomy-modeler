<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Page\PatternSection;
use Taxmod\Core\Page\StarterPattern;

/**
 * Eine Seitenvorlage wird ein Startmuster (D-870).
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class StarterPatternTest extends TestCase
{
    /** ⚠️ *Sein Wort: «so ein Template ist ein Seitenaufbau, der immer gleich ist».* */
    #[Test]
    public function a_template_becomes_headings_with_what_it_presets_below_them(): void
    {
        $muster = new StarterPattern('Retro Hauptplatine', '<!-- wp:paragraph --><p>Vorspann</p><!-- /wp:paragraph -->', [
            new PatternSection('Ansichten', 1, '', '<!-- wp:gallery {"linkTo":"none"} --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->'),
            new PatternSection('Fakten & Daten', 2, 'Was das Board kann'),
            new PatternSection('Jumper', 3),
        ]);

        $markup = $muster->markup();

        self::assertStringStartsWith('<!-- wp:paragraph --><p>Vorspann</p>', $markup);
        self::assertStringContainsString("<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Ansichten</h1>", $markup);
        self::assertStringContainsString('<!-- wp:gallery {"linkTo":"none"} -->', $markup, 'die Vorgabe steht, wie sie ist');
        self::assertStringContainsString("<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Fakten &amp; Daten</h2>", $markup, 'Ebene 2 ist die Vorgabe des Blocks und steht ohne Angabe');
        self::assertStringContainsString('<!-- wp:paragraph {"placeholder":"Was das Board kann"} -->', $markup, 'ohne Vorgabe: der Hilfetext als Platzhalter');
        self::assertStringContainsString("<!-- wp:paragraph -->\n<p></p>", $markup, 'ohne Hilfetext ein leerer Absatz');
        self::assertLessThan(strpos($markup, 'Jumper'), strpos($markup, 'Ansichten'), 'die Reihenfolge der Abschnitte bleibt');
    }
}
