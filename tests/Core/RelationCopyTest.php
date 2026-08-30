<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\Relation;
use Taxmod\Core\Model\RelationKind;

/**
 * Eine abgeleitete Kante trägt alles mit, was niemand geändert hat.
 *
 * ⚠️ **Gemessen am 2026-08-30, bevor [D-528](../../docs/NewConcept/90-decision-log.md) ein drittes
 * getragenes Feld hinzufügte:** *von zehn Stellen, die `new self(...)` schreiben, liessen **zwei**
 * `hide` weg — `renamedTo()` und `revived()`. Eine Kante umbenennen machte sie sichtbar, ohne dass
 * jemand das gesagt hätte.*
 *
 * ⚠️ *Darum steht dieser Test **vor** der Multiplizitätsspalte: ein viertes Feld erbte denselben
 * Fehler, und ein Feld, das beim Kopieren still auf seine Vorgabe zurückfällt, ist genau die Sorte
 * Fehler, die erst auffällt, wenn jemand die Daten schon geändert hat.*
 */
final class RelationCopyTest extends TestCase
{
    private function verstecktesFeld(): Relation
    {
        return Relation::attribute(4654, 3988, 1171, RelationKind::Composition, 'exponent', 1)
            ->withHide(true);
    }

    #[Test]
    public function renaming_an_edge_keeps_it_hidden(): void
    {
        self::assertTrue(
            $this->verstecktesFeld()->renamedTo('anders')->hide,
            'umbenennen darf ein verstecktes Feld nicht sichtbar machen'
        );
    }

    #[Test]
    public function reviving_an_edge_keeps_it_hidden(): void
    {
        self::assertTrue(
            $this->verstecktesFeld()->parkedBy(99)->revived()->hide,
            'wiederherstellen darf ein verstecktes Feld nicht sichtbar machen'
        );
    }
    /**
     * ⚠️ **Das vierte getragene Feld, und der Grund für {@see Relation::copy()}.** *Ein Feld mit
     * `1..*` umzubenennen und dabei still auf `1..1` zurückzufallen hiesse: der Benutzer kann seine
     * bereits eingegebenen Werte nicht mehr speichern, und niemand hat je etwas geändert.*
     */
    #[Test]
    public function every_derived_edge_keeps_its_multiplicity(): void
    {
        $feld = Relation::attribute(4654, 3988, 1171, RelationKind::Composition, 'exponent', 1, Multiplicity::OneToMany);

        self::assertSame(Multiplicity::OneToMany, $feld->renamedTo('anders')->multiplicity);
        self::assertSame(Multiplicity::OneToMany, $feld->withHide(true)->multiplicity);
        self::assertSame(Multiplicity::OneToMany, $feld->movedTo(7)->multiplicity);
        self::assertSame(Multiplicity::OneToMany, $feld->withKind(RelationKind::Setting)->multiplicity);
        self::assertSame(Multiplicity::OneToMany, $feld->parkedBy(99)->revived()->multiplicity);
    }

    /** ⚠️ *Die Vorgabe ist `1..1` — [D-434](../../docs/NewConcept/90-decision-log.md), «weil das der Standard beim Eingeben ist».* */
    #[Test]
    public function an_edge_nobody_configured_requires_exactly_one(): void
    {
        self::assertSame(
            Multiplicity::ExactlyOne,
            Relation::attribute(1, 2, 3, RelationKind::Composition, 'feld', 0)->multiplicity
        );
    }
}