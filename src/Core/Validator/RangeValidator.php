<?php declare(strict_types=1);

namespace Taxmod\Core\Validator;

use Taxmod\Core\Model\SimpleType;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Model\Type\SpecialisedTypes;

/**
 * Liegt der Wert in seinen Grenzen?
 *
 * ⚠️ **Die Frage, die der Typ nicht stellen kann.** *`int` verweigert `zwoelf` selbst — gemessen. Ob
 * `1000` erlaubt ist, weiss nur die Einstellung `max` an dieser Verwendungsstelle. **Das ist die
 * Trennlinie aus [R36a](../../../docs/NewConcept/30-renderer.md#r36a--the-converter-removes-what-cannot-have-been-meant-the-validator-asks-about-the-rest):
 * der Typ sagt, was ein Wert **ist**, die Einstellung, was er **sein darf**.***
 *
 * ⚠️ **`min` und `max`, und keine erfundenen Namen.** *[R17](../../../docs/NewConcept/30-renderer.md#r12r17)
 * nennt `min`, `max` und `step` in einem Atemzug als Angaben eines Zahlenknotens, und
 * {@see SettingKey} führt genau diese drei. Ein `range_min` steht im Änderungsbuch als **alter** Name —
 * nicht im Modell, und ein Validator, der einen nicht existierenden Schlüssel liest, prüft nie etwas.*
 *
 * ⚠️ **`step` prüft er nicht.** *Ein Schritt ist eine Angabe für das **Bedienelement** — «wie weit
 * springt der Griff» — und kein Verbot. Ein Wert zwischen zwei Schritten kann aus einem Import oder aus
 * einer Rechnung kommen, und ihn zu beanstanden hiesse, eine Zeichenangabe zu einer Regel zu machen.*
 *
 * ⚠️ **Verglichen wird über {@see TypedValue::comparedTo()}** — *nicht mit einem eigenen Vergleich.
 * «Wie zwei Werte sich vergleichen» ist eine Tatsache, und eine zweite Heimat dafür wäre genau der
 * Fehler, der hier am meisten gekostet hat. Der eine Vergleich hält auch [D-057](../../../docs/NewConcept/90-decision-log.md):
 * eine Dezimalzahl wird nie zu `float`.*
 *
 * ⚠️ *Auch für `datetime`: Grenzen einer Zeitangabe sind dieselbe Frage.*
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class RangeValidator implements Validator
{
    public const NAME = 'range';

    public function name(): string
    {
        return self::NAME;
    }

    public function handles(): array
    {
        // ⚠️ **Abgelesen und nicht aufgezählt** ([D-484](../../../docs/NewConcept/90-decision-log.md)).
        // *Welche Typen eine Grenze vertragen, sagt der Typ selbst — {@see \Taxmod\Core\Model\Type\SpecialisedType::hasBounds()}.
        // Hier stand dieselbe Auskunft als zweite Liste, und der Eigentümer wollte genau das nicht:
        // «wenn ich einen int-Knoten habe, kann ich das softwaretechnisch prüfen.»*
        return SpecialisedTypes::withBounds();
    }

    public function check(TypedValue $value, ?SimpleType $type, array $settings): array
    {
        // ⚠️ *Ein fehlender Wert ist keine Beanstandung: ob einer **da sein muss**, sagt die
        // Multiplizität ([D-549](../../../docs/NewConcept/90-decision-log.md)), und sie sagt es allein.*
        if ($value->isNothing()) {
            return [];
        }

        $aus = [];

        $unten = $settings['min'] ?? null;
        $oben  = $settings['max'] ?? null;

        // ⚠️ *`comparedTo()` gibt `null` für «nicht vergleichbar» — dann wird **nicht** beanstandet.
        // Eine Grenze, die nicht zum Wert passt, ist ein Modellfehler und keine Regelverletzung des
        // Benutzers; ihn dafür anzusprechen wäre die falsche Adresse.*
        if ($unten !== null && $value->comparedTo($unten) === -1) {
            $aus[] = new Complaint(self::NAME, 'below_min', ['min' => $this->asCharacters($unten)]);
        }

        if ($oben !== null && $value->comparedTo($oben) === 1) {
            $aus[] = new Complaint(self::NAME, 'above_max', ['max' => $this->asCharacters($oben)]);
        }

        return $aus;
    }

    /** ⚠️ *Für die Meldung, nicht für den Vergleich — der Platzhalter ist Text (`R36b`).* */
    private function asCharacters(TypedValue $grenze): string
    {
        return $grenze->describe();
    }
}
