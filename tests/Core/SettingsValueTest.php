<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Exception\ConcurrentChange;
use Taxmod\Core\Exception\MalformedSettingsValue;
use Taxmod\Core\Model\Setting\SettingsObject;
use Taxmod\Core\Model\Setting\SettingsValue;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Tests\Core\Fake\InMemorySettings;

/**
 * Die Zeile und das Objekt des Einstellungsmodells — die vier Zusagen aus Anforderung 4.4, und
 * der Speicher, der «Löschen ist Wandern» hält (D-712, D-717).
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class SettingsValueTest extends TestCase
{
    #[Test]
    public function a_value_hangs_on_exactly_one_carrier(): void
    {
        $amKnoten = SettingsValue::atNode(7, 'IntegerNode', 'max', TypedValue::ofInt(999));
        $imObjekt = SettingsValue::inObject(3, 'CompactRenderer', 'orientation', TypedValue::ofText('vertical'));

        self::assertSame(7, $amKnoten->nodeId);
        self::assertNull($amKnoten->objectId);
        self::assertSame(3, $imObjekt->objectId);
        self::assertNull($imObjekt->nodeId);
        self::assertSame('IntegerNode.max', $amKnoten->address());
    }

    #[Test]
    public function the_edge_is_an_addition_and_never_the_carrier(): void
    {
        $anDerKante = SettingsValue::atNode(7, 'IntegerNode', 'max', TypedValue::ofInt(99), relationId: 42);

        self::assertTrue($anDerKante->isAtEdge());
        self::assertSame(42, $anDerKante->relationId);

        $this->expectException(MalformedSettingsValue::class);

        SettingsValue::fromStorage(1, 1, null, null, 42, 'IntegerNode', 'max', 0, true, 1, null, null, null, null);
    }

    #[Test]
    public function a_value_holds_exactly_one_value(): void
    {
        $objekt = SettingsValue::objectAtNode(7, 'Node', 'renderer', 5, position: 1);

        self::assertTrue($objekt->holdsAnObject());
        self::assertTrue($objekt->value->isNothing());

        $this->expectException(MalformedSettingsValue::class);

        SettingsValue::atNode(7, 'Node', 'read_only', TypedValue::nothing());
    }

    #[Test]
    public function neither_a_date_nor_a_record_reference_is_a_setting(): void
    {
        foreach ([TypedValue::ofDate('2026-09-11'), TypedValue::ofRecordReference(9)] as $wert) {
            try {
                SettingsValue::atNode(7, 'Node', 'x', $wert);
                self::fail($wert->typeName() . ' sollte abgewiesen werden');
            } catch (MalformedSettingsValue) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function a_bool_lives_in_the_int_column(): void
    {
        $zeile = SettingsValue::atNode(7, 'Node', 'read_only', TypedValue::ofBool(true));

        self::assertSame(1, $zeile->value->int);
        self::assertTrue($zeile->value->asBool());
    }

    #[Test]
    public function an_address_is_class_plus_attribute_and_never_empty(): void
    {
        $this->expectException(MalformedSettingsValue::class);

        SettingsValue::atNode(7, '', 'max', TypedValue::ofInt(1));
    }

    #[Test]
    public function the_store_hands_out_ids_per_table_and_reads_by_carrier(): void
    {
        $store  = new InMemorySettings();
        $objekt = $store->addObject(SettingsObject::create('CompactRenderer'));
        $zeile  = $store->addValue(SettingsValue::objectAtNode(7, 'Node', 'renderer', $objekt->id));
        $innen  = $store->addValue(SettingsValue::inObject($objekt->id, 'CompactRenderer', 'orientation', TypedValue::ofText('vertical')));
        $kante  = $store->addValue(SettingsValue::inObject($objekt->id, 'CompactRenderer', 'orientation', TypedValue::ofText('horizontal'), relationId: 42));

        self::assertSame(1, $objekt->id);
        self::assertSame([1, 2, 3], [$zeile->id, $innen->id, $kante->id]);
        self::assertSame([$zeile], $store->valuesOfNodes([7])[7]);
        self::assertSame([$innen, $kante], $store->valuesOfObjects([$objekt->id])[$objekt->id], 'am Objekt: die eigene und die an der Kante');
        self::assertSame([], $store->valuesOfNodes([8])[8]);
    }

    #[Test]
    public function saving_keeps_the_old_version_in_the_shadow_and_refuses_a_stale_one(): void
    {
        $store = new InMemorySettings();
        $zeile = $store->addValue(SettingsValue::atNode(7, 'IntegerNode', 'max', TypedValue::ofInt(999)));

        $store->saveValue($zeile->withValue(TypedValue::ofInt(99)), $zeile->version);

        self::assertSame(99, $store->findValue($zeile->id)?->value->int);
        self::assertSame(2, $store->findValue($zeile->id)?->version);
        self::assertSame(999, $store->shadow[0]->value->int);

        $this->expectException(ConcurrentChange::class);

        $store->saveValue($zeile->withValue(TypedValue::ofInt(1)), $zeile->version);
    }

    #[Test]
    public function forgetting_is_moving_to_the_shadow_and_an_object_takes_its_rows_along(): void
    {
        $store  = new InMemorySettings();
        $objekt = $store->addObject(SettingsObject::create('Umrechnung'));
        $store->addValue(SettingsValue::inObject($objekt->id, 'Umrechnung', 'factor', TypedValue::ofDecimal('1000')));
        $store->addValue(SettingsValue::inObject($objekt->id, 'Umrechnung', 'offset', TypedValue::ofDecimal('0')));

        self::assertSame(1, $store->forgetObject($objekt->id));
        self::assertNull($store->findObject($objekt->id));
        self::assertSame(0, $store->countValues());
        self::assertCount(2, $store->shadow);
    }

    #[Test]
    public function a_reference_to_a_node_is_found_from_the_node(): void
    {
        $store = new InMemorySettings();
        $store->addValue(SettingsValue::atNode(7, 'Einheitswert', 'erlaubte_praefixe', TypedValue::ofReference(4004), position: 1));
        $store->addValue(SettingsValue::atNode(7, 'Einheitswert', 'erlaubte_praefixe', TypedValue::ofReference(4014), position: 2));

        self::assertCount(1, $store->valuesReferring([4004]));
        self::assertSame(4004, $store->valuesReferring([4004])[0]->value->reference);
        self::assertCount(0, $store->valuesReferring([1]));
    }
}
