<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\FieldRenderer;
use Taxmod\Core\Renderer\Level;
use Taxmod\Core\Renderer\Purpose;
use Taxmod\Core\Renderer\RenderContext;
use Taxmod\Core\Renderer\RenderResult;
use Taxmod\Core\Renderer\RenderedField;
use Taxmod\Core\Renderer\RepeatableRenderer;
use Taxmod\Core\Renderer\Surroundings;

/**
 * Das Gerüst für mehrere Werte — [D-527](../../docs/NewConcept/90-decision-log.md).
 *
 * ⚠️ **Die Zusage, die hier zählt, ist die Zuordnung über den Pfad.** *Drei Werte eines Feldes teilen
 * sich **eine** Kante; ordnete das Gerüst die Entfernen-Knöpfe nach ihrer **Stellung** zu, träfe der
 * zweite Klick den falschen Eintrag, sobald einer entfernt wurde — denn die Nummern rücken absichtlich
 * nicht nach.*
 */
final class RepeatableRendererTest extends TestCase
{
    private Relation $feld;
    private RepeatableRenderer $renderer;

    protected function setUp(): void
    {
        $this->feld     = Relation::attribute(4654, 3988, 1171, RelationKind::Composition, 'exponent', 1);
        $this->renderer = new RepeatableRenderer();
    }

    private function eintrag(string $pfad, string $markup): RenderedField
    {
        return new RenderedField($this->feld, SimpleType::Int, FieldRenderer::NAME, new RenderResult($markup, [4654]), false, $pfad);
    }

    private function gezeichnet(array $parts, array $actions): string
    {
        return $this->renderer->render(
            $this->feld,
            new RenderContext(
                purpose: Purpose::Edit,
                value: TypedValue::nothing(),
                level: Level::Admin,
                surroundings: new Surroundings(parts: $parts, actions: $actions),
            )
        )->markup;
    }

    #[Test]
    public function every_entry_is_drawn_in_order(): void
    {
        $markup = $this->gezeichnet(
            [$this->eintrag('4654.1', 'erster'), $this->eintrag('4654.3', 'dritter')],
            []
        );

        self::assertStringContainsString('erster', $markup);
        self::assertStringContainsString('dritter', $markup);
        self::assertLessThan(strpos($markup, 'dritter'), strpos($markup, 'erster'));
    }

    /**
     * ⚠️ **Der Kern.** *Die Liste hat eine Lücke — `.1` und `.3`, weil `.2` entfernt wurde und die
     * Nummern nicht nachrücken. **Nach Stellung zugeordnet bekäme «dritter» den Knopf für `.2`** und
     * der nächste Klick löschte den falschen Wert.*
     */
    #[Test]
    public function each_entry_gets_the_control_that_names_its_own_path(): void
    {
        $markup = $this->gezeichnet(
            [$this->eintrag('4654.1', 'erster'), $this->eintrag('4654.3', 'dritter')],
            [
                new Control(RepeatableRenderer::REMOVE, '4654.1', 'weg', 'den ersten entfernen'),
                new Control(RepeatableRenderer::REMOVE, '4654.3', 'weg', 'den dritten entfernen'),
            ]
        );

        self::assertLessThan(
            strpos($markup, 'dritter'),
            strpos($markup, 'den ersten entfernen'),
            'der Knopf für .1 muss vor dem Eintrag .3 stehen'
        );

        self::assertGreaterThan(
            strpos($markup, 'dritter'),
            strpos($markup, 'den dritten entfernen'),
            'der Knopf für .3 muss nach dem Eintrag .3 stehen'
        );
    }

    /**
     * ⚠️ *Ein Knopf, dessen Pfad in der Liste nicht vorkommt, wird **nicht** gezeichnet — sonst
     * erschiene ein Entfernen für einen Wert, den es nicht gibt.*
     */
    #[Test]
    public function a_control_for_an_absent_path_is_not_drawn(): void
    {
        $markup = $this->gezeichnet(
            [$this->eintrag('4654.1', 'erster')],
            [new Control(RepeatableRenderer::REMOVE, '4654.2', 'weg', 'geistereintrag')]
        );

        self::assertStringNotContainsString('geistereintrag', $markup);
    }

    /**
     * ⚠️ **Eine leere Liste bleibt eine Liste.** *Verschwände sie, könnte man einem Feld ohne Werte
     * nie einen geben — der Knopf zum Hinzufügen ist der einzige Weg hinein.*
     */
    #[Test]
    public function an_empty_list_still_offers_the_way_in(): void
    {
        $markup = $this->gezeichnet([], [new Control(RepeatableRenderer::ADD, '4654', 'mehr', 'einen weiteren')]);

        self::assertStringContainsString('einen weiteren', $markup);
        self::assertStringContainsString('taxmod-repeatable', $markup);
    }

    /**
     * ⚠️ **Es kennt keinen Typ, und das ist der Sinn** — *ein Behälter gilt für jeden Typ, weil er
     * keinen anfasst. Meldete es einen, wäre es für alle anderen plötzlich nicht mehr zuständig.*
     */
    #[Test]
    public function it_claims_no_type_and_fits_a_use_site(): void
    {
        self::assertSame([], $this->renderer->handles());
        self::assertTrue($this->renderer->fits($this->feld));
        self::assertFalse($this->renderer->fits(\Taxmod\Core\Model\Node::create(1, 'irgendwas', null)));
    }
}
