<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Converter\BinaryConverter;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\Type\IntType;
use Taxmod\Core\Model\Type\SpecialisedType;
use Taxmod\Core\Model\Type\SpecialisedTypes;
use Taxmod\Core\Renderer\CheckboxRenderer;
use Taxmod\Core\Renderer\RendererNode;
use Taxmod\Core\Validator\RangeValidator;
use Taxmod\Core\Validator\ShapeValidator;

/**
 * Das Inventar der spezialisierten Typen — der Teil, der ohne Datenbank messbar ist.
 *
 * ⚠️ *Die gesäten Knoten misst `scripts/dev/simple-type-check.php`; hier steht, was der Kern allein
 * verspricht ([D-484](../../docs/NewConcept/90-decision-log.md)).*
 */
final class SpecialisedTypeTest extends TestCase
{
    #[Test]
    public function es_gibt_genau_eine_klasse_je_aufzaehlungsfall(): void
    {
        self::assertCount(count(SimpleType::cases()), SpecialisedTypes::CLASSES);

        $gesehen = [];

        foreach (SpecialisedTypes::all() as $one) {
            self::assertInstanceOf(SpecialisedType::class, $one);
            self::assertSame($one->type(), SpecialisedTypes::ofClass($one::class));

            $gesehen[] = $one->type()->value;
        }

        self::assertSame(
            array_map(static fn (SimpleType $t): string => $t->value, SimpleType::cases()),
            $gesehen,
            'Reihenfolge und Vollständigkeit der Fälle'
        );
    }

    /** ⚠️ *Der Ordner ist die Gegenprobe zur handgeführten Liste — eine neue Klasse muss eingetragen werden.* */
    #[Test]
    public function keine_klasse_im_ordner_fehlt_im_inventar(): void
    {
        $imOrdner = [];

        foreach (glob(dirname(__DIR__, 2) . '/src/Core/Model/Type/*.php') ?: [] as $datei) {
            $name    = 'Taxmod\\Core\\Model\\Type\\' . basename($datei, '.php');
            $spiegel = new \ReflectionClass($name);

            if (! $spiegel->isAbstract() && $spiegel->isSubclassOf(SpecialisedType::class)) {
                $imOrdner[] = $name;
            }
        }

        self::assertSame([], array_diff($imOrdner, SpecialisedTypes::CLASSES));
    }

    /** ⚠️ *Der Fall ist die Adresse, die Klasse ist die Wahrheit — die Aufzählung leitet nur weiter.* */
    #[Test]
    public function die_aufzaehlung_antwortet_mit_ihrer_klasse(): void
    {
        foreach (SimpleType::cases() as $case) {
            $klasse = $case->specialised();

            self::assertSame($klasse->column(), $case->column());
            self::assertSame($klasse->nodeName(), $case->nodeName());
            self::assertSame($klasse->humanName(), $case->humanName());
            self::assertSame($klasse->pattern(), $case->pattern());
            self::assertSame($klasse->inputMode(), $case->inputMode());
        }
    }

    /** ⚠️ *Sein Satz: «eine Funktionalität für min/max muss da sein, und ich kann sie prüfen.»* */
    #[Test]
    public function die_validatoren_lesen_ab_was_der_typ_kann(): void
    {
        self::assertSame(
            [SimpleType::Int, SimpleType::Decimal, SimpleType::DateTime],
            SpecialisedTypes::withBounds()
        );

        self::assertSame(SpecialisedTypes::withBounds(), (new RangeValidator())->handles());

        self::assertSame(
            [SimpleType::Email, SimpleType::Color, SimpleType::Version],
            SpecialisedTypes::withAShape()
        );

        self::assertSame(SpecialisedTypes::withAShape(), (new ShapeValidator())->handles());
    }

    /**
     * ⚠️ **Die spezialisierte Klasse ist die Klasse des Knotens** ([D-620](../../docs/NewConcept/90-decision-log.md)).
     *
     * *Sein Einwand: «einen int-Knoten und eine int-Klasse zusätzlich zu führen, reisst was
     * auseinander, das eigentlich zusammengehört.» Also kommt eine Zeile, die eine Typklasse nennt,
     * **als diese Klasse** an — der Unterscheider steht in `implemented_by` und braucht keinen
     * Vorfahrenlauf.*
     */
    #[Test]
    public function eine_zeile_mit_typklasse_kommt_als_diese_klasse_an(): void
    {
        foreach (SpecialisedTypes::all() as $steckbrief) {
            $knoten = Node::fromStorage(7, 3, $steckbrief->nodeName(), '1.7', null, $steckbrief::class);

            self::assertInstanceOf($steckbrief::class, $knoten);
            self::assertSame(7, $knoten->id);
            self::assertSame($steckbrief->type(), $knoten->type());
        }
    }

    /** ⚠️ *«die Renderer werden Knoten … dass die Renderer selbst Knoten sind» — D-620.* */
    #[Test]
    public function eine_zeile_mit_rendererklasse_kommt_als_diese_klasse_an(): void
    {
        $knoten = Node::fromStorage(9, 1, 'checkbox', '1.9', null, CheckboxRenderer::class);

        self::assertInstanceOf(CheckboxRenderer::class, $knoten);
        self::assertSame('checkbox', $knoten->name());
        self::assertInstanceOf(RendererNode::class, $knoten);
    }

    /**
     * ⚠️ **Nicht jeder Knoten bekommt eine Klasse, und D-620 sagt das ausdrücklich.**
     *
     * *Die überwiegende Mehrheit ist Inhalt des Eigentümers. Und was eine Klasse nennt, die **kein**
     * Knoten ist — ein Konverter, ein Validator, oder eine, die es nicht mehr gibt —, bleibt ebenfalls
     * ein schlichtes `Node`: die Hydrierung stürzt daran nicht ab.*
     */
    #[Test]
    public function alles_andere_bleibt_ein_schlichtes_node(): void
    {
        foreach ([null, '', BinaryConverter::class, 'Taxmod\\Nicht\\Vorhanden'] as $spalte) {
            $knoten = Node::fromStorage(11, 1, 'Kunde', '1.11', null, $spalte);

            self::assertSame(Node::class, $knoten::class, var_export($spalte, true));
        }
    }

    /** ⚠️ *Ein umbenannter Typknoten bleibt sein Typ — späte statische Bindung statt `new self`.* */
    #[Test]
    public function eine_aenderung_behaelt_die_klasse(): void
    {
        $knoten = Node::fromStorage(7, 3, 'Integer', '1.7', null, IntType::class);

        self::assertInstanceOf(IntType::class, $knoten->renamedTo('Ganzzahl'));
        self::assertInstanceOf(IntType::class, $knoten->movedUnder('1.2'));
        self::assertInstanceOf(IntType::class, $knoten->withAssignedId(12));
    }
}
