<?php declare(strict_types=1);

namespace Taxmod\Core\Converter;

use Taxmod\Core\Exception\NotAValueOfThatType;
use Taxmod\Core\Model\TypedValue;

/**
 * Der eine Stellenwert-Gang, den `binary`, `octal` und `hexadecimal` gehen — die Basis ist ihr
 * ganzer Unterschied.
 *
 * ⚠️ **Eine Stelle und nicht drei** ([D-523](../../../docs/NewConcept/90-decision-log.md)). *Drei
 * Klassen mit demselben Gang wären dreimal derselbe Fehler zu beheben — und **beide Fehler unten
 * waren schon da**, in der einen Klasse, die es gab.*
 *
 * ⚠️ **Gerechnet wird auf der negativen Seite, und das ist der Grund für den ganzen `intdiv`-Gang
 * statt `dechex(abs(…))`.** *Der `int` reicht nach unten **eine Zahl weiter** als nach oben, also hat
 * `PHP_INT_MIN` keinen Betrag: `abs(PHP_INT_MIN)` ist ein `float`, und `dechex()` nimmt keinen
 * `float`. **Gemessen am 2026-08-29**: `HexadecimalConverter::shown()` warf dort einen `TypeError` —
 * kein Domänenfehler, sondern ein Absturz beim **Zeichnen** eines Wertes, den die `bigint`-Spalte
 * tragen darf.*
 *
 * ⚠️ **Was nicht mehr in einen `int` passt, wird verweigert und nicht gekappt.** *Ebenfalls gemessen:
 * `written('FFFFFFFFFFFFFFFF')` gab **`0`** zurück — `(int)` auf einen zu grossen `float`. Das ist
 * genau die stille Null, gegen die der Docblock derselben Klasse geschrieben war: «a zero that
 * arrived that way is indistinguishable afterwards from a zero somebody meant»
 * ([D-071](../../../docs/NewConcept/90-decision-log.md)).*
 *
 * ```mermaid
 * flowchart LR
 *   N["-19"] -->|digits, Basis 16| C["-13"]
 *   C -->|number, Basis 16| N
 * ```
 *
 * @see docs/NewConcept/30-renderer.md
 */
final class PositionalNotation
{
    /** Grossbuchstaben, weil eine Basis über zehn welche braucht und zwei und acht keine haben. */
    private const DIGITS = '0123456789ABCDEF';

    /**
     * Die Ziffern der Zahl in dieser Basis, mit dem Vorzeichen davor.
     *
     * ⚠️ **Kein `0x`, kein `0b`, kein führendes `0`** — die Zeichen sind die Zahl und sonst nichts.
     * *Ein Präfix, das beim Hinausgehen steht und beim Hereinkommen freiwillig ist, sind zwei Formen
     * für einen Wert, und der Rundgang ist dann keiner mehr.*
     *
     * ⚠️ **Negative behalten ihr Vorzeichen vorn.** *`-1` als `FFFFFFFF` wäre Zweierkomplement — eine
     * andere Abbildung, die an einer Breite hängt, die niemand genannt hat, und sie käme durch ein
     * `bigint` nicht zurück.*
     *
     * @param int<2, 16> $base
     */
    public static function digits(int $number, int $base): string
    {
        if ($number === 0) {
            return '0';
        }

        $negative = $number < 0;
        $left     = $negative ? $number : -$number;
        $written  = '';

        while ($left !== 0) {
            $written = self::DIGITS[-($left % $base)] . $written;
            $left    = intdiv($left, $base);
        }

        return ($negative ? '-' : '') . $written;
    }

    /**
     * Die Zahl hinter den Zeichen — oder eine Absage.
     *
     * ⚠️ **Der Überlauf wird **vor** der Rechnung geprüft, nicht danach.** *Danach ist es zu spät:
     * ein `int`, der überläuft, wird in PHP still zu einem `float`, und der Vergleich, der das merken
     * sollte, rechnet dann schon mit dem falschen Wert.*
     *
     * @param int<2, 16> $base
     * @param string     $converter Der Name, der in der Absage steht — die Absage nennt die
     *                              Abbildung, an der sie gescheitert ist, nicht diese Klasse.
     *
     * @throws NotAValueOfThatType wenn die Zeichen keine Zahl dieser Basis sind oder keine, die ein
     *                             `int` trägt
     */
    public static function number(string $characters, int $base, string $converter): TypedValue
    {
        $trimmed  = trim($characters);
        $negative = str_starts_with($trimmed, '-');
        $written  = strtoupper($negative ? substr($trimmed, 1) : $trimmed);
        $alphabet = substr(self::DIGITS, 0, $base);

        if ($written === '') {
            throw NotAValueOfThatType::submitted($characters, $converter);
        }

        // Auf der negativen Seite gesammelt, aus demselben Grund wie oben: sie ist die längere.
        $value = 0;
        $room  = intdiv(PHP_INT_MIN, $base);

        for ($at = 0, $end = strlen($written); $at < $end; ++$at) {
            $digit = strpos($alphabet, $written[$at]);

            if ($digit === false || $value < $room) {
                throw NotAValueOfThatType::submitted($characters, $converter);
            }

            $value *= $base;

            if ($value < PHP_INT_MIN + $digit) {
                throw NotAValueOfThatType::submitted($characters, $converter);
            }

            $value -= $digit;
        }

        if (! $negative) {
            // ⚠️ *Der Betrag von `PHP_INT_MIN` ist selbst keine `int`-Zahl mehr.*
            if ($value === PHP_INT_MIN) {
                throw NotAValueOfThatType::submitted($characters, $converter);
            }

            $value = -$value;
        }

        return TypedValue::ofInt($value);
    }
}
