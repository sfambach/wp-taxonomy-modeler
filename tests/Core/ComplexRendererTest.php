<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\FieldRenderer;
use Taxmod\Core\Renderer\FormRenderer;
use Taxmod\Core\Renderer\ComplexRenderer;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\RenderResult;
use Taxmod\Core\Renderer\RenderedField;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Renderer\Surroundings;

/**
 * Der Komplex-Renderer — [D-758](../../docs/NewConcept/90-decision-log.md).
 *
 * ⚠️ **Seine Reihenfolge:** *feste Angaben kompakt, dann die einfachen Felder, dann je komplexem Feld mit höchstens
 * einem Wert ein Formular mit Überschrift, dann je komplexem Feld mit mehreren eine Tabelle mit Überschrift; tiefer
 * Liegendes als Zusammenfassung.*
 */
final class ComplexRendererTest extends TestCase
{
    private function feld(int $id, string $name, string $markup, string $summary = '', array $rows = [], Multiplicity $wieOft = Multiplicity::ExactlyOne): RenderedField
    {
        $kante = Relation::attribute($id, 1, $id + 1000, RelationKind::Composition, $name, $id)->withMultiplicity($wieOft);

        return new RenderedField($kante, $rows === [] ? SimpleType::Text : null, $rows === [] ? FieldRenderer::NAME : FormRenderer::NAME, new RenderResult($markup, [$id]), rows: $rows, summary: $summary);
    }

    /** @param list<RenderedField> $parts */
    private function gezeichnet(array $parts, array $lead = [], Purpose $purpose = Purpose::Edit): string
    {
        return (new ComplexRenderer())->render(
            Node::create(1, 'Kontakt', null),
            new RenderContext(
                purpose: $purpose,
                value: TypedValue::nothing(),
                surroundings: new Surroundings(parts: $parts, rowLead: $lead === [] ? [] : [$lead]),
            )
        )->markup;
    }

    #[Test]
    public function the_order_is_fixed_simple_single_many(): void
    {
        $strasse = $this->feld(21, 'Street', '<input name="s">', 'Hauptstr.');
        $ort     = $this->feld(22, 'City', '<input name="c">', 'Bonn');

        $markup = $this->gezeichnet(
            [
                $this->feld(30, 'Phones', 'MEHRERE', '', [[$this->feld(31, 'Number', 'N1', '0228')], [$this->feld(31, 'Number', 'N2', '0221')]], Multiplicity::ZeroToMany),
                $this->feld(20, 'Address', 'EINZELN', '', [[$strasse, $ort]]),
                $this->feld(10, 'Name', '<input name="n">', 'Meier'),
            ],
            ['Record' => '#12']
        );

        $fest    = strpos($markup, 'taxmod-complex-fixed');
        $name    = strpos($markup, 'name="n"');
        $address = strpos($markup, '>Address');
        $phones  = strpos($markup, '>Phones');

        self::assertNotFalse($fest);
        self::assertTrue($fest < $name && $name < $address && $address < $phones, 'fest, einfach, einzeln, mehrere');
        self::assertStringContainsString('taxmod-form', substr($markup, $address, $phones - $address), 'höchstens eins: ein Formular');
        self::assertStringContainsString('<table', substr($markup, $phones), 'mehrere: eine Tabelle');
        self::assertSame(2, substr_count(substr($markup, $phones), '<tr class="taxmod-table-row">'), 'je Wert eine Zeile');
        self::assertStringNotContainsString('EINZELN', $markup, 'der Teil wird neu ausgelegt, nicht übernommen');
    }

    #[Test]
    public function a_part_below_a_part_is_its_summary_on_display(): void
    {
        $innen   = $this->feld(41, 'Street', '<input name="s">', 'Hauptstr.');
        $adresse = $this->feld(40, 'Address', 'ADRESSE', '', [[$innen, $this->feld(42, 'City', '<input>', 'Bonn')]]);

        $markup = $this->gezeichnet([$this->feld(50, 'Supplier', 'X', '', [[$this->feld(51, 'Name', '<input>', 'ACME'), $adresse]])], [], Purpose::Display);

        self::assertStringContainsString('<details class="taxmod-complex-link"><summary>Hauptstr., Bonn</summary>', $markup);
    }

    /** D-894, sein Wort: «ich denke bei der eingabe sollten die felder zu sehen sein». */
    #[Test]
    public function a_part_below_a_part_shows_its_fields_when_editing(): void
    {
        $innen   = $this->feld(41, 'Street', '<input name="s">', 'Hauptstr.');
        $adresse = $this->feld(40, 'Address', 'ADRESSE', '', [[$innen, $this->feld(42, 'City', '<input>', 'Bonn')]]);

        $markup = $this->gezeichnet([$this->feld(50, 'Supplier', 'X', '', [[$this->feld(51, 'Name', '<input>', 'ACME'), $adresse]])]);

        self::assertStringNotContainsString('taxmod-complex-link', $markup, 'nichts zugeklappt');
        self::assertStringContainsString('ADRESSE', $markup, 'die Felder des Teils stehen da');
    }

    #[Test]
    public function it_is_offered_like_form_and_table(): void
    {
        self::assertContains(ComplexRenderer::NAME, ShippedRenderers::registry()->namesForNodes());
        self::assertSame([], (new ComplexRenderer())->handles());
    }
}
