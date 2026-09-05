<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\Type\SpecialisedType;
use Taxmod\Core\Model\Type\SpecialisedTypes;
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
}
