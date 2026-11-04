<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Printing\FilledLabel;
use Taxmod\Core\Printing\LabelContent;
use Taxmod\Core\Printing\LabelDocument;
use Taxmod\Core\Printing\LabelFormat;
use Taxmod\Core\Printing\LabelLayout;
use Taxmod\Core\Printing\LabelPrefix;
use Taxmod\Core\Printing\LabelTemplate;

/**
 * D-1153: a label's layout as JSON — read, checked against its label, drawn from a record's values.
 *
 * @see docs/NewConcept/90-decision-log.md D-1153
 */
final class LabelLayoutTest extends TestCase
{
    #[Test]
    public function his_german_sketch_reads_as_a_layout(): void
    {
        $layout = LabelLayout::fromJson('[{"typ":"text","feld":"bezeichnung","x":2,"y":2,"b":58,"h":10,"pt":14,"fett":true},'
            . '{"typ":"qr","feld":"code","x":2,"y":14,"b":34,"h":34},{"typ":"text","feld":"projekt","x":38,"y":24,"b":22,"pt":8,"zeilen":2}]');

        self::assertSame(['bezeichnung', 'code', 'projekt'], $layout->fields());
        self::assertTrue($layout->elements[0]->bold);
        self::assertSame(2, $layout->elements[2]->lines);
        self::assertTrue($layout->fitsOn(LabelFormat::byKey('holder62')));
        self::assertFalse($layout->fitsOn(LabelFormat::byKey('material62')));
        self::assertEquals($layout, LabelLayout::fromJson($layout->toJson()));
    }

    #[Test]
    public function the_layout_carries_its_own_examples(): void
    {
        $layout = LabelLayout::fromJson('[{"type":"qr","field":"code","x":2,"y":2,"w":20,"example":"O-12345"},'
            . '{"typ":"text","feld":"kurzzeichen","x":24,"y":2,"b":30,"beispiel":"A1-S1"},{"type":"text","field":"code","x":24,"y":10,"w":30,"example":"O-9"},'
            . '{"type":"text","field":"projekt","x":24,"y":20,"w":30}]');

        self::assertSame(['code' => 'O-12345', 'kurzzeichen' => 'A1-S1'], $layout->examples());
        self::assertEquals($layout, LabelLayout::fromJson($layout->toJson()));
        self::assertStringContainsString('"example":"A1-S1"', $layout->toJson());
    }

    #[Test]
    public function a_broken_layout_is_refused_with_its_reason(): void
    {
        foreach (['{"type":"text"}', '[]', '[{"type":"barcode","field":"code","w":5}]', '[{"type":"text","w":5}]', 'nicht json'] as $broken) {
            try {
                LabelLayout::fromJson($broken);
                self::fail('accepted: ' . $broken);
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    #[Test]
    public function the_values_of_a_record_fill_the_layout(): void
    {
        $layout = LabelLayout::fromJson('[{"type":"qr","field":"code","x":3,"y":3,"w":24},{"type":"text","field":"kurzzeichen","x":30,"y":3,"w":29,"h":8,"pt":14,"bold":true},'
            . '{"type":"text","field":"fehlt","x":30,"y":12,"w":29,"h":5}]');
        $pdf = LabelDocument::renderLayout(LabelFormat::byKey('material62'), $layout, [['code' => 'O-47786', 'kurzzeichen' => 'A1-S1'], ['code' => 'O-1', 'kurzzeichen' => 'B']]);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('(A1-S1) Tj', $pdf);
        self::assertSame(2, substr_count($pdf, '/Type /Page '));
    }

    #[Test]
    public function the_shipped_templates_are_named_by_purpose_and_print_the_old_label(): void
    {
        $templates = LabelTemplate::shipped();

        self::assertSame(['Platz', 'Kiste', 'Material', 'Einzelstück', 'Versand'], array_values(array_map(static fn (LabelTemplate $t): string => $t->name(), $templates)));
        self::assertSame(2, $templates['kiste']->copies);

        $label = LabelContent::of(LabelPrefix::Place, 47786, 'Werkzeug', 'A1-S1');
        foreach ($templates as $template) {
            $pdf = LabelDocument::renderLayout($template->format, $template->layout, [LabelTemplate::valuesOf($label)]);
            self::assertStringContainsString('(Werkzeug) Tj', $pdf, $template->key);
            self::assertStringContainsString('(O-47786) Tj', $pdf, $template->key);
        }
    }

    #[Test]
    public function a_filled_label_travels_as_json_and_prints_the_same(): void
    {
        $template = LabelTemplate::shipped()['platz'];
        $filled   = new FilledLabel($template->format, $template->layout, ['code' => 'O-47786', 'title' => 'Werkzeug', 'subtitle' => 'A1-S1'], $template->name());
        $back     = FilledLabel::fromJson($filled->toJson());

        self::assertEquals($filled, $back);
        self::assertSame('O-47786', $back->code());
        self::assertSame(3, substr_count($back->render(3), '/Type /Page '));
        $this->expectException(\InvalidArgumentException::class);
        FilledLabel::fromJson('{"format":{"key":"x","w":10,"h":10},"layout":[{"type":"qr","field":"code","x":0,"y":0,"w":30}],"values":{}}');
    }
}
