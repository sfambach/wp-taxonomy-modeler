<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\ReferenceSpace;
use Taxmod\Core\Model\TypedValue;

/**
 * Ein Verweis nennt seinen Raum — TASK-005.
 *
 * ⚠️ *Der Kern trägt die Zusage, nicht die Datenbank: `value_ref_kind` kann nur gefüllt werden, wenn
 * der Wert selbst weiss, worauf er zeigt. Der Wächter am Rand
 * (`scripts/dev/value-ref-space-check.php`) prüft dann die Daten.*
 */
final class ReferenceSpaceTest extends TestCase
{
    public function testAReferenceWithoutASpaceIsANodeReference(): void
    {
        self::assertSame(ReferenceSpace::Node, TypedValue::ofReference(7)->referenceSpace);
    }

    public function testARecordReferenceSaysSo(): void
    {
        $wert = TypedValue::ofRecordReference(7);

        self::assertSame(7, $wert->reference);
        self::assertSame(ReferenceSpace::Record, $wert->referenceSpace);
    }

    public function testAValueWithoutAReferenceHasNoSpace(): void
    {
        self::assertNull(TypedValue::ofInt(7)->referenceSpace);
        self::assertNull(TypedValue::nothing()->referenceSpace);
    }

    /** Was aus der Ablage kommt, behält seinen Raum — sonst wäre die Spalte umsonst geschrieben. */
    public function testStorageKeepsTheSpace(): void
    {
        $wert = TypedValue::fromStorage(null, null, null, null, 7, ReferenceSpace::Record);

        self::assertSame(ReferenceSpace::Record, $wert->referenceSpace);
    }

    /** Eine alte Zeile ohne Angabe wird als Knotenverweis gelesen — was sie bis Schema 20 bedeutete. */
    public function testAStoredReferenceWithoutASpaceReadsAsANode(): void
    {
        self::assertSame(
            ReferenceSpace::Node,
            TypedValue::fromStorage(null, null, null, null, 7, null)->referenceSpace
        );
    }

    /** Der Journal-Rundlauf trägt den Raum mit, sonst zeigt ein Rückspielen in die falsche Tabelle. */
    public function testTheJournalRoundTripKeepsTheSpace(): void
    {
        foreach ([TypedValue::ofReference(7), TypedValue::ofRecordReference(7)] as $wert) {
            $zurueck = TypedValue::ofTypeName($wert->typeName(), $wert->rawValue());

            self::assertSame($wert->reference, $zurueck->reference);
            self::assertSame($wert->referenceSpace, $zurueck->referenceSpace);
        }
    }

    /** Eine Journalzeile von vor TASK-005 sagt nur «reference» und bleibt lesbar. */
    public function testAnOlderJournalRowStillReads(): void
    {
        self::assertSame(ReferenceSpace::Node, TypedValue::ofTypeName('reference', '7')->referenceSpace);
    }
}
