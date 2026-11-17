<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Addon\Complaint;
use Taxmod\Core\Description\ControlDescription;
use Taxmod\Core\Description\FieldDescription;
use Taxmod\Core\Description\Message;
use Taxmod\Core\Description\PageDescription;
use Taxmod\Core\Description\RowDescription;
use Taxmod\Core\Description\SectionDescription;
use Taxmod\Core\Event\Address;
use Taxmod\Core\Renderer\Control;

/** Plan step 6 (D-628, D-629): structure → JSON → structure is the same object, field for field. */
final class DescriptionTest extends TestCase
{
    public static function sample(): PageDescription
    {
        $feld = new FieldDescription(
            new Address(12, 5, 7),
            'spinner',
            'int',
            '1',
            'Menge «ä» <b>',
            true,
            false,
            [Message::fromComplaint((new Complaint('range', 'below_min', ['min' => '3']))->at(new Address(12, 5, 7)))]
        );

        return new PageDescription(
            'Bauteile',
            Address::ofNode(12),
            [
                new SectionDescription('Fields', false, [
                    new RowDescription(0, Address::ofEdge(12, 5), [$feld], [
                        ControlDescription::fromControl((new Control('do', 'field_up', 'Up', '', false, false, 'arrow-up-alt2'))->at(Address::ofEdge(12, 5))),
                    ]),
                ]),
                new SectionDescription('Records', true),
            ],
            [new Message('saved')]
        );
    }

    public function testTheRoundTripIsLossless(): void
    {
        $seite = self::sample();

        self::assertEquals($seite, PageDescription::fromJson($seite->toJson()));
        self::assertSame($seite->toJson(), PageDescription::fromJson($seite->toJson())->toJson());
    }

    public function testNoMarkupTravels(): void
    {
        self::assertStringNotContainsString('<button', self::sample()->toJson());
        self::assertStringContainsString('<b>', self::sample()->toJson(), 'a label is text, kept as it is — escaping is the drawer\'s');
    }
}
