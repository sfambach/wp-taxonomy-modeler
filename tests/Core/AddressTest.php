<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Event\Address;
use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\ControlMarkup;

/** Plan step 1 (D-1424): a place in the model, written so a button can carry it. */
final class AddressTest extends TestCase
{
    public function testTheTokenNamesOnlyWhatIsSet(): void
    {
        self::assertSame('node=3', Address::ofNode(3)->token());
        self::assertSame('node=3;edge=8', Address::ofEdge(3, 8)->token());
        self::assertSame('node=3;record=9;field=min', Address::ofRecord(3, 9)->withField('min')->token());
    }

    public function testTokenAndAddressAreOneThing(): void
    {
        $voll = new Address(12, 5, 7, 'a_b:c-d');

        self::assertTrue(Address::fromToken($voll->token())->equals($voll));
    }

    public function testAFieldIsAKeyNotText(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Address::ofNode(1)->withField('<b>');
    }

    public function testNoAddressWithoutANode(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Address::fromToken('edge=3');
    }

    public function testAControlKeepsEverythingButGainsItsPlace(): void
    {
        $knopf = Control::saving('do', 'save_record', 'Save', 'Write', true, 'f1');
        $hier  = $knopf->at(Address::ofRecord(4, 6));

        self::assertSame([$knopf->name, $knopf->value, $knopf->glyph, $knopf->form], [$hier->name, $hier->value, $hier->glyph, $hier->form]);
        self::assertStringContainsString('data-taxmod-address="node=4;record=6"', ControlMarkup::button($hier));
        self::assertStringNotContainsString('data-taxmod-address', ControlMarkup::button($knopf));
    }
}
