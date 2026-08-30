<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\ResolvedSetting;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\FieldRenderer;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\RenderResult;
use Taxmod\Core\Renderer\RenderedField;
use Taxmod\Core\Renderer\Surroundings;
use Taxmod\Core\Renderer\TableRenderer;

/**
 * Mehrere Datensätze untereinander — [D-542](../../docs/NewConcept/90-decision-log.md).
 *
 * ⚠️ **Sein Vergleich:** *«wie der Form-Renderer ein Formular erstellt, soll der Table-Renderer eine
 * Tabelle erstellen … er bekommt auch einen Knoten und kann **mehrere Datensätze untereinander**
 * darstellen».* Zeilen sind Datensätze, Spalten sind Felder.
 */
final class TableRendererTest extends TestCase
{
    private TableRenderer $renderer;

    private Relation $name;

    private Relation $menge;

    protected function setUp(): void
    {
        $this->renderer = new TableRenderer();

        // Zwei Felder mit ausdrücklicher Stellung, damit die Spaltenreihenfolge prüfbar ist.
        $this->name  = Relation::attribute(10, 1, 2, RelationKind::Composition, 'Name', 1);
        $this->menge = Relation::attribute(20, 1, 3, RelationKind::Composition, 'Menge', 2);
    }

    private function feld(Relation $kante, string $markup): RenderedField
    {
        return new RenderedField($kante, SimpleType::Text, FieldRenderer::NAME, new RenderResult($markup, [$kante->id]));
    }

    /**
     * @param list<list<RenderedField>>            $records
     * @param array<string,ResolvedSetting>        $settings
     */
    private function gezeichnet(array $records, array $settings = [], array $parts = []): string
    {
        return $this->renderer->render(
            Node::create(1, 'Zutat', null),
            new RenderContext(
                purpose: Purpose::Display,
                value: TypedValue::nothing(),
                level: Level::Admin,
                settings: $settings,
                surroundings: new Surroundings(parts: $parts, records: $records),
            )
        )->markup;
    }

    #[Test]
    public function rows_are_records_and_columns_are_fields(): void
    {
        $markup = $this->gezeichnet([
            [$this->feld($this->name, 'Mehl'), $this->feld($this->menge, '500 g')],
            [$this->feld($this->name, 'Zucker'), $this->feld($this->menge, '100 g')],
        ]);

        self::assertSame(2, substr_count($markup, '<tr class="taxmod-table-row">'), 'zwei Datensaetze, zwei Zeilen');
        self::assertSame(4, substr_count($markup, '<td'), 'zwei Zeilen mal zwei Spalten');

        self::assertLessThan(strpos($markup, 'Zucker'), strpos($markup, 'Mehl'), 'in der gegebenen Reihenfolge');
        self::assertLessThan(strpos($markup, 'Mehl'), strpos($markup, 'Name'), 'der Kopf steht oben');
    }

    /**
     * ⚠️ **Der Kern.** *Fehlt einem Datensatz ein Feld, muss die Zelle **leer** erscheinen und nicht
     * wegfallen — sonst verrutscht die ganze Zeile, und eine verrutschte Zeile sieht wie Daten aus.*
     */
    #[Test]
    public function a_missing_field_leaves_an_empty_cell_and_does_not_shift_the_row(): void
    {
        $markup = $this->gezeichnet([
            [$this->feld($this->name, 'Mehl'), $this->feld($this->menge, '500 g')],
            [$this->feld($this->menge, '100 g')],
        ]);

        self::assertSame(4, substr_count($markup, '<td'), 'beide Zeilen haben zwei Zellen');
        self::assertStringContainsString('<td class="taxmod-table-cell"></td>', $markup, 'die fehlende ist leer');
    }

    /**
     * ⚠️ *Die Spalten kommen aus **allen** Datensätzen: ein Feld, das nur der zweite trägt, ist
     * trotzdem eine Spalte — und sie stehen in der Reihenfolge des Modells, nicht in der des Zufalls.*
     */
    #[Test]
    public function columns_come_from_every_record_in_model_order(): void
    {
        $markup = $this->gezeichnet([
            [$this->feld($this->menge, '500 g')],
            [$this->feld($this->name, 'Zucker')],
        ]);

        self::assertSame(2, substr_count($markup, 'taxmod-table-head'), 'zwei Spalten, obwohl kein Datensatz beide hat');
        self::assertLessThan(strpos($markup, 'Menge'), strpos($markup, 'Name'), 'Name steht vor Menge, wie im Modell');
    }

    /** ⚠️ *`with_label` ist die Einstellung, die er mit `form` teilt — darum hängt sie an ihrer Gruppe.* */
    #[Test]
    public function with_label_switches_the_head_off(): void
    {
        $ohne = $this->gezeichnet(
            [[$this->feld($this->name, 'Mehl')]],
            ['with_label' => new ResolvedSetting('with_label', TypedValue::ofBool(false), 1, true)]
        );

        self::assertStringNotContainsString('<thead>', $ohne);
        self::assertStringContainsString('Mehl', $ohne, 'die Daten bleiben');
    }

    /**
     * ⚠️ *Ohne die Einstellung wird der Kopf gezeichnet — **eine Tabelle ohne Spaltennamen ist
     * schlechter zu lesen als eine mit**, also ist das die richtige Vorgabe.*
     */
    #[Test]
    public function the_head_is_drawn_when_nobody_said_otherwise(): void
    {
        self::assertStringContainsString('<thead>', $this->gezeichnet([[$this->feld($this->name, 'Mehl')]]));
    }

    /**
     * ⚠️ *Ein einzelner Satz Teile ist eine Tabelle mit einer Zeile — so bleibt der Renderer dort
     * brauchbar, wo bisher nur ein Datensatz gezeichnet wurde.*
     */
    #[Test]
    public function a_single_set_of_parts_is_a_one_row_table(): void
    {
        $markup = $this->gezeichnet([], [], [$this->feld($this->name, 'Mehl')]);

        self::assertSame(1, substr_count($markup, '<tr class="taxmod-table-row">'));
        self::assertStringContainsString('Mehl', $markup);
    }

    /** ⚠️ *Nichts zu zeichnen heisst nichts zeichnen — keine leere Tabelle als Gerippe.* */
    #[Test]
    public function nothing_to_draw_draws_nothing(): void
    {
        self::assertSame('', $this->gezeichnet([]));
    }

    /** ⚠️ **Kein Typ, und das ist der Punkt** — *ein Behälter gilt für jeden Typ, weil er keinen anfasst.* */
    #[Test]
    public function it_claims_no_type_and_fits_a_node(): void
    {
        self::assertSame([], $this->renderer->handles());
        self::assertTrue($this->renderer->fits(Node::create(1, 'irgendwas', null)));
        self::assertFalse($this->renderer->fits($this->name));
    }
}
