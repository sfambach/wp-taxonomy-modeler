<?php declare(strict_types=1);

namespace Taxmod\Core\Printing;

/**
 * A component value in the short code printed on tiny parts and their labels: the unit prefix stands where the decimal
 * comma would be (IEC 60062) — 24 kΩ is «24K», 4,7 kΩ «4K7», 0,22 Ω «0R22», 100 nF «100N», 4,7 µF «4U7».
 *
 * ⚠️ *His choice 2026-11-07 on the card «Wie soll der Wert auf dem SMD-Etikett stehen?»: «Kurz: 24K», and: «Wobei das K hier
 * dann das Komma auch ist, ne? Und bei Kondensatoren … N oder U».* A text that is not a number with a unit stays as it is.
 *
 * @see docs/NewConcept/90-decision-log.md D-1217
 */
final class ShortValue
{
    /** Unit prefix ⇒ letter; an ohm value without a prefix takes «R». */
    private const LETTERS = ['p' => 'P', 'n' => 'N', 'µ' => 'U', 'μ' => 'U', 'u' => 'U', 'm' => 'm', 'k' => 'K', 'K' => 'K', 'M' => 'M', 'G' => 'G'];

    public static function of(string $value): string
    {
        $text = trim($value);
        if (! preg_match('/^(\d+)(?:[.,](\d+))?\s*([pnµμumkKMG])?\s*(Ω|Ohm|ohm|R|F|H|V|A|W)?$/u', $text, $m)) {
            return $text;
        }
        $whole    = $m[1];
        $fraction = rtrim($m[2] ?? '', '0');
        $prefix   = $m[3] ?? '';
        $unit     = $m[4] ?? '';
        if ($prefix === '' && $unit === '') {
            return $text;
        }
        if ($prefix !== '') {
            $letter = self::LETTERS[$prefix];
        } elseif (in_array($unit, ['Ω', 'Ohm', 'ohm', 'R'], true)) {
            $letter = 'R';
        } else {
            return $text;
        }

        return $fraction === '' ? $whole . $letter : $whole . $letter . $fraction;
    }
}
