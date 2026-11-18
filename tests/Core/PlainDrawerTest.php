<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Description\ControlDescription;
use Taxmod\Core\Description\FieldDescription;
use Taxmod\Core\Description\PageDescription;
use Taxmod\Core\Description\RowDescription;
use Taxmod\Core\Description\SectionDescription;
use Taxmod\Core\Event\Address;
use Taxmod\Tests\Support\PageInventory;
use Taxmod\Tests\Support\PlainDrawer;

/** Plan step 6b (D-636): the second drawer builds the page from the description alone — nothing missing, nothing added. */
final class PlainDrawerTest extends TestCase
{
    public function testTheDrawnPageHoldsWhatTheDescriptionHolds(): void
    {
        $seite = DescriptionTest::sample();

        self::assertSame(PageInventory::of($seite), PageInventory::read(PlainDrawer::draw($seite)));
    }

    public function testTheInventoryNamesFieldsValuesOrderAndControls(): void
    {
        self::assertSame([
            'page node=12 «Bauteile»',
            'message saved @-',
            'section «Fields»',
            'field node=12;edge=5;record=7 «Menge «ä» <b>» = 1',
            'message below_min @node=12;edge=5;record=7',
            'control do=field_up @node=12;edge=5 unavailable «Up»',
            'section «Records»',
        ], PageInventory::read(PlainDrawer::draw(DescriptionTest::sample())));
    }

    public function testAMissingOrExtraPieceIsSeen(): void
    {
        $seite  = DescriptionTest::sample();
        $weniger = new PageDescription($seite->title, $seite->address, [new SectionDescription('Fields')], $seite->messages);
        $mehr   = new PageDescription($seite->title, $seite->address, [...$seite->sections, new SectionDescription('Extra', false, [
            new RowDescription(0, Address::ofNode(12), [new FieldDescription(Address::ofNode(12)->withField('x'), 'plain', 'text', 'a', 'X', true, true)], [
                new ControlDescription('do', 'trash', 'Trash', false),
            ]),
        ])], $seite->messages);

        self::assertNotSame(PageInventory::of($seite), PageInventory::read(PlainDrawer::draw($weniger)));
        self::assertNotSame(PageInventory::of($seite), PageInventory::read(PlainDrawer::draw($mehr)));
        self::assertSame(PageInventory::of($mehr), PageInventory::read(PlainDrawer::draw($mehr)), 'hidden field and unavailable control survive the drawing');
    }

    public function testReadingAnotherDrawersMarkupNeedsOnlyTheContract(): void
    {
        $fremd = '<main data-taxmod-address="node=3"><h1>T</h1><section><h2>S</h2>'
            . '<div class="x"><table><tr><td data-taxmod-field="node=3;edge=4"><label>Farbe</label><select><option>rot</option><option selected>blau</option></select></td>'
            . '<td><button class="button" name="do" value="trash" data-taxmod-address="node=3;edge=4" disabled><span class="dashicons"></span>Weg</button></td></tr></table></div></section></main>';

        self::assertSame([
            'page node=3 «T»',
            'section «S»',
            'field node=3;edge=4 «Farbe» = blau',
            'control do=trash @node=3;edge=4 unavailable «Weg»',
        ], PageInventory::read($fremd));
    }
}
