<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Renderer\CheckboxRenderer;
use Taxmod\Core\Renderer\FieldRenderer;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RendererChoiceRenderer;
use Taxmod\Core\Renderer\RendererRegistry;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Renderer\SpinnerRenderer;
use Taxmod\Core\Renderer\ToggleRenderer;

/**
 * Die Renderer-Wahl — **von den Knoten aus, gesiebt durch die Registratur**.
 *
 * ⚠️ *Warum das hier steht und nicht nur am Rand ([D-647](../../docs/NewConcept/90-decision-log.md)):
 * `einstellungen-check` geht den Weg des Benutzers und sieht deshalb nur, was **heute im
 * Bestand steht** — und heute trägt **kein einziger** Renderer-Knoten eine `select`-Beschriftung, die
 * drei belegten sitzen woanders. Die Zusage «die Rolle schlägt den Namen» wäre dort grün, ohne je
 * geprüft worden zu sein. Hier wird sie geprüft.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class RendererChoiceTest extends TestCase
{
    private RendererChoiceRenderer $waehler;
    private RendererRegistry $registry;

    protected function setUp(): void
    {
        $this->waehler  = new RendererChoiceRenderer();
        $this->registry = new RendererRegistry();

        $this->registry->add(new FieldRenderer(), SimpleType::Int);
        $this->registry->add(new SpinnerRenderer());
        $this->registry->add(new ToggleRenderer(), SimpleType::Bool);
    }

    /** @return array<string, Node> */
    private function candidates(): array
    {
        return [
            FieldRenderer::class   => Node::create(11, 'field', null),
            SpinnerRenderer::class => Node::create(12, 'spinner', null),
            ToggleRenderer::class  => Node::create(13, 'toggle', null),
        ];
    }

    #[Test]
    public function the_registry_sieves_the_nodes(): void
    {
        $offer = $this->waehler->offer(
            Node::create(1, 'Menge', null),
            SimpleType::Int,
            $this->registry,
            $this->candidates()
        );

        // Der Schalter zeichnet keine Ganzzahl — also ist sein Knoten hier keine Möglichkeit.
        self::assertSame([11 => 'field', 12 => 'spinner'], $offer);
    }

    #[Test]
    public function a_renderer_without_a_node_cannot_be_offered(): void
    {
        // ⚠️ **Sein Einwand** ([D-647](../../docs/NewConcept/90-decision-log.md)): *«in der Registry
        // stehen aktuell nur die Renderer-Namen, nicht die Knoten-Ids, die du eigentlich speichern
        // musst.»* Gemessen 27 Kennungen gegen 17 Knoten — von der Registratur aus gedacht stünden
        // zehn Einträge da, die niemand speichern kann.
        $this->registry->add(new CheckboxRenderer());

        $offer = $this->waehler->offer(
            Node::create(1, 'Schalter', null),
            SimpleType::Bool,
            $this->registry,
            $this->candidates()
        );

        self::assertSame([13 => 'toggle'], $offer);
    }

    #[Test]
    public function a_node_whose_class_nobody_registered_is_not_a_possibility(): void
    {
        // Der Zwischenknoten `render with label` fällt genau so heraus — ohne dass ihn jemand
        // löschen oder verschieben müsste.
        $candidates = $this->candidates();

        $candidates['Taxmod\Core\Renderer\GibtEsNicht'] = Node::create(14, 'render with label', null);

        self::assertArrayNotHasKey(
            14,
            $this->waehler->offer(Node::create(1, 'Menge', null), SimpleType::Int, $this->registry, $candidates)
        );
    }

    #[Test]
    public function the_select_label_beats_the_name_and_sorts_the_list(): void
    {
        // ⚠️ **Seine Schärfung** ([D-647](../../docs/NewConcept/90-decision-log.md)): *«vielleicht
        // sogar eher select label»*. *Sortiert wird nach dem, was dasteht — sonst stünde die Liste
        // nach einer Kennung, die niemand liest.*
        $offer = $this->waehler->offer(
            Node::create(1, 'Menge', null),
            SimpleType::Int,
            $this->registry,
            $this->candidates(),
            [12 => 'Drehfeld', 11 => 'Zahlenfeld']
        );

        self::assertSame([12 => 'Drehfeld', 11 => 'Zahlenfeld'], $offer);
    }

    #[Test]
    public function without_a_label_the_name_carries_it(): void
    {
        // Gemessen am 2026-09-05: 3 `select`-Beschriftungen gegen 195 Namen — und keine davon auf
        // einem Renderer-Knoten. Der Rückfall trägt es heute durchweg.
        $offer = $this->waehler->offer(
            Node::create(1, 'Menge', null),
            SimpleType::Int,
            $this->registry,
            $this->candidates(),
            [11 => 'Zahlenfeld']
        );

        self::assertSame([11 => 'Zahlenfeld', 12 => 'spinner'], $offer);
    }

    #[Test]
    public function what_lies_in_the_trash_is_not_a_possibility(): void
    {
        $trash = Node::create(9, 'Trash', null);

        $candidates = [
            FieldRenderer::class   => Node::create(11, 'field', null),
            SpinnerRenderer::class => Node::create(12, 'spinner', '9'),
        ];

        self::assertSame(
            [11 => 'field'],
            $this->waehler->offer(
                Node::create(1, 'Menge', null),
                SimpleType::Int,
                $this->registry,
                $candidates,
                [],
                $trash
            )
        );
    }

    #[Test]
    public function the_preselection_goes_through_the_class_and_not_through_the_shown_text(): void
    {
        // ⚠️ **Der Grund, warum das eine eigene Methode ist.** *Solange die Liste Knotennamen trug,
        // fand ein Namensvergleich den Eintrag noch. Mit der `select`-Beschriftung fände er ihn nicht
        // mehr — Kennung und Anzeigetext sind zwei Wörter (`AR-2`).*
        $candidates = $this->candidates();

        $offer = $this->waehler->offer(
            Node::create(1, 'Menge', null),
            SimpleType::Int,
            $this->registry,
            $candidates,
            [12 => 'Drehfeld']
        );

        self::assertSame(12, $this->waehler->chosenIn($offer, $candidates, $this->registry, 'spinner'));
        self::assertNull($this->waehler->chosenIn($offer, $candidates, $this->registry, 'Drehfeld'));

        // Was nicht in der Liste steht, ist auch keine Vorauswahl — sonst zeigte das Feld einen
        // Eintrag, den es nicht anbietet.
        self::assertNull($this->waehler->chosenIn($offer, $candidates, $this->registry, 'toggle'));
        self::assertNull($this->waehler->chosenIn($offer, $candidates, $this->registry, null));
    }

    #[Test]
    public function the_chooser_itself_is_internal_and_gets_no_node(): void
    {
        // ⚠️ **[D-648](../../docs/NewConcept/90-decision-log.md)**, sein Wort: *«für den
        // Renderer-Renderer erstellen wir da einen Knoten? … würde eher nein sagen, ist was
        // Internes.»* **Ein Knoten machte ihn wählbar** — und die Saat sät nur `namesForNodes()`.
        $shipped = ShippedRenderers::registry();

        self::assertContains(RendererChoiceRenderer::NAME, $shipped->namesForSurfaces());
        self::assertNotContains(RendererChoiceRenderer::NAME, $shipped->namesForNodes());

        // Und er steht auch in keiner Wahl, die die Registratur selbst aufstellt.
        foreach ([SimpleType::Int, SimpleType::Bool, SimpleType::Text, SimpleType::NodeRef, null] as $type) {
            foreach ($shipped->eligibleFor(Node::create(1, 'Menge', null), $type, Purpose::Edit) as $one) {
                self::assertNotSame(RendererChoiceRenderer::NAME, $one->name());
            }
        }
    }

    #[Test]
    public function every_choosable_renderer_has_a_class_to_bind_a_node_to(): void
    {
        // ⚠️ *Die Kernhälfte der Brücke aus [D-620](../../docs/NewConcept/90-decision-log.md): dass
        // **an einem echten Bestand** auch ein Knoten daran hängt, prüft `einstellungen-check`.*
        $shipped = ShippedRenderers::registry();

        self::assertSame(
            count($shipped->namesForNodes()),
            count($shipped->classesForNodes()),
            'eine Kennung ohne Klasse hätte keinen Weg zu einem Knoten'
        );
    }
}
