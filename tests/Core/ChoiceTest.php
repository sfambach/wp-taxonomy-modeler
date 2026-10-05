<?php declare(strict_types=1);

namespace Taxmod\Tests\Core;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Renderer\Choice;

/**
 * Die **Wahl** — R28–R32, an einer Stelle geprüft.
 *
 * ⚠️ **Diese Zusagen standen vorher nur als Markup-Prüfungen an fünf Orten.** *Der Eigentümer hat den
 * Grund benannt: «von der Multiplizität zum Choice ist ein Weg … und es kann sein, dass du den mehrfach
 * erfindest». **Hier ist der Weg einmal, und er ist prüfbar, ohne Markup zu lesen.***
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class ChoiceTest extends TestCase
{
    /** Die Tabelle aus R28–R32, Zeile für Zeile. */
    #[Test]
    public function the_rule_table_holds_for_every_combination(): void
    {
        $faelle = [
            // [Möglichkeiten, Multiplizität, Ausgänge, entschieden, unerfüllbar]
            [0, Multiplicity::ZeroToOne,  1, true,  false],
            [0, Multiplicity::ZeroToMany, 1, true,  false],
            [0, Multiplicity::ExactlyOne, 0, true,  true],
            [0, Multiplicity::OneToMany,  0, true,  true],
            [1, Multiplicity::ZeroToOne,  2, false, false],
            [1, Multiplicity::ExactlyOne, 1, true,  false],
            [3, Multiplicity::ZeroToOne,  4, false, false],
            [3, Multiplicity::ExactlyOne, 3, false, false],
        ];

        foreach ($faelle as [$wieviele, $multiplicity, $ausgaenge, $entschieden, $unerfuellbar]) {
            $wahl = Choice::atUseSite($multiplicity, $this->options($wieviele));

            $wie = $wieviele . ' Möglichkeiten bei ' . $multiplicity->value;

            self::assertSame($ausgaenge, $wahl->outcomes(), "Ausgänge, {$wie}");
            self::assertSame($entschieden, $wahl->isDecided(), "entschieden, {$wie}");
            self::assertSame($unerfuellbar, $wahl->isUnsatisfiable(), "unerfüllbar, {$wie}");
        }
    }

    /**
     * ⚠️ **Der Kern von R28: nicht die Länge der Liste zählt, sondern die Ausgänge.** *Ein Eintrag, der
     * leer bleiben darf, sind **zwei** — und der bleibt bedienbar.*
     */
    #[Test]
    public function one_entry_that_may_be_empty_is_two_outcomes_and_stays_operable(): void
    {
        $wahl = Choice::atUseSite(Multiplicity::ZeroToOne, $this->options(1));

        self::assertSame(2, $wahl->outcomes());
        self::assertTrue($wahl->isOperable());
    }

    /** ⚠️ *Und derselbe Eintrag als Pflicht ist **einer** und ist entschieden.* */
    #[Test]
    public function one_mandatory_entry_is_decided(): void
    {
        $wahl = Choice::atUseSite(Multiplicity::ExactlyOne, $this->options(1));

        self::assertSame(1, $wahl->outcomes());
        self::assertTrue($wahl->isDecided());
        self::assertFalse($wahl->isOperable());
    }

    /** ⚠️ *Nicht bedienbar heisst nicht entschieden — ein geerbtes Feld ist bedienbar woanders.* */
    #[Test]
    public function not_editable_is_not_the_same_as_decided(): void
    {
        $wahl = Choice::atUseSite(Multiplicity::ZeroToOne, $this->options(3), null, false);

        self::assertFalse($wahl->isDecided());
        self::assertFalse($wahl->isOperable());
    }

    // ------------------------------------------------------- Einstellungen

    /**
     * ⚠️ **Bei einer Einstellung ist «nichts» fast immer ein Ausgang** — *Einstellungen sind spärlich
     * ([D-015](../../docs/NewConcept/90-decision-log.md)), und es gibt keine Pflichteinstellung.*
     */
    #[Test]
    public function a_setting_may_almost_always_be_nothing(): void
    {
        $wahl = Choice::forSetting($this->options(2));

        self::assertTrue($wahl->mayBeNothing);
        self::assertSame(3, $wahl->outcomes());
    }

    /** ⚠️ *Ausser bei einer geschlossenen Liste wie den vier Multiplizitäten — dort nicht.* */
    #[Test]
    public function a_closed_list_has_no_empty_outcome(): void
    {
        $wahl = Choice::forSetting($this->options(4), true);

        self::assertFalse($wahl->mayBeNothing);
        self::assertSame(4, $wahl->outcomes());
    }

    // ------------------------------------------------------- der Zustand

    /**
     * ⚠️ **Der Fall, den ein Kerntest gefangen hat.** *Ein gespeicherter Wert über einer leeren Liste
     * wäre als leeres gesperrtes Auswahlfeld gezeichnet worden — **der Wert stand nirgends mehr**, und
     * das nächste Speichern hätte «nichts» geschrieben.*
     */
    #[Test]
    public function an_empty_choice_cannot_show_a_stored_value(): void
    {
        self::assertFalse(
            Choice::atUseSite(Multiplicity::ZeroToOne, [], TypedValue::ofReference(7))->canShowItsState()
        );

        self::assertTrue(
            Choice::atUseSite(Multiplicity::ZeroToOne, [], TypedValue::nothing())->canShowItsState()
        );

        self::assertTrue(
            Choice::atUseSite(Multiplicity::ZeroToOne, $this->options(1), TypedValue::ofReference(7))->canShowItsState()
        );
    }

    /**
     * ⚠️ **[D-360](../../docs/NewConcept/90-decision-log.md):** *was gespeichert ist, bleibt stehen, auch
     * wenn es heute nicht mehr angeboten würde.* Und damit kann die Wahl ihren Zustand zeigen.
     */
    #[Test]
    public function a_stored_value_can_be_added_as_an_entry(): void
    {
        $wahl = Choice::atUseSite(Multiplicity::ZeroToOne, [], TypedValue::ofReference(7))
            ->including(7, 'Gramm');

        self::assertTrue($wahl->canShowItsState());
        self::assertSame([7 => 'Gramm'], $wahl->options);
        self::assertSame(2, $wahl->outcomes());
    }

    /** ⚠️ *Zweimal derselbe Eintrag gibt es nicht — die Wahl bleibt unverändert.* */
    #[Test]
    public function including_something_already_offered_changes_nothing(): void
    {
        $wahl  = Choice::atUseSite(Multiplicity::ZeroToOne, [7 => 'Gramm']);
        $wieder = $wahl->including(7, 'Gramm anders');

        self::assertSame($wahl, $wieder);
    }

    /**
     * ⚠️ **Verengen behält die Reihenfolge des Modells** — *`position` ist die einzige Heimat der
     * Ordnung, und ein Eintrag, der nicht mehr passt, fällt weg statt ans Ende zu rutschen.*
     */
    #[Test]
    public function narrowing_keeps_the_model_order(): void
    {
        $wahl = Choice::atUseSite(Multiplicity::ZeroToOne, [
            'field'   => 'field',
            'slider'  => 'slider',
            'spinner' => 'spinner',
            'toggle'  => 'toggle',
        ])->narrowedTo(['spinner', 'field']);

        self::assertSame(['field' => 'field', 'spinner' => 'spinner'], $wahl->options);
    }

    /** ⚠️ *Und Verengen rührt `mayBeNothing` nicht an — das kommt aus der Multiplizität, nicht der Liste.* */
    #[Test]
    public function narrowing_does_not_touch_whether_nothing_is_allowed(): void
    {
        $eng = Choice::atUseSite(Multiplicity::ExactlyOne, [1 => 'a', 2 => 'b'])->narrowedTo([1]);

        self::assertFalse($eng->mayBeNothing);
        self::assertSame(1, $eng->outcomes());
        self::assertTrue($eng->isDecided());
    }

    /** @return array<int, string> */
    private function options(int $wieviele): array
    {
        $aus = [];

        for ($i = 1; $i <= $wieviele; $i++) {
            $aus[$i] = 'Eintrag ' . $i;
        }

        return $aus;
    }
}
