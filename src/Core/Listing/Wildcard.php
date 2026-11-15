<?php declare(strict_types=1);

namespace Taxmod\Core\Listing;

/**
 * The one rule for what a search or a filter term matches (D-1357): «contains», ignoring case, and «*» stands for any
 * characters, none included. Nothing else is special — «?», «%» and «_» are what they are. His word 2026-11-14: «Als
 * Wildcard hätte ich gerne das Sternchen.» `assets/list-head.js` repeats this rule for the rows a page drew; SQL takes it
 * through {@see self::parts()} and {@see self::spellings()}.
 *
 * D-1378: what a keyboard cannot type is not needed to find it. Both sides are read in one spelling ({@see self::canonical()}):
 * «µ» is «u», «Ω» is «ohm», a space between a number and its unit does not count, and a decimal comma is a point — so «10 uf»,
 * «10uF» and «10 µF» are the same. His word 2026-11-15: *«ich kann nach 10 uf nicht suchen und micro zeichen kann ich nicht auf
 * der tastatur»*.
 *
 * @see docs/NewConcept/90-decision-log.md D-1357, D-1378
 */
final class Wildcard
{
    /** The micro signs (U+00B5, Greek U+03BC) and the ohm signs (U+2126, Greek U+03A9 and its small letter) a search reads as typed. */
    private const SIGNS = ["\u{00B5}" => 'u', "\u{03BC}" => 'u', "\u{2126}" => 'ohm', "\u{03A9}" => 'ohm', "\u{03C9}" => 'ohm'];

    /** At most this many spellings of one piece go into SQL — a term with many numbers stays one short query. */
    private const MOST_SPELLINGS = 48;

    /** Whether `$text` holds `$term`, its pieces between «*» in this order. An empty term matches everything. */
    public static function matches(string $text, string $term): bool
    {
        $at   = 0;
        $text = self::canonical($text);

        foreach (self::parts($term) as $part) {
            $part  = self::canonical($part);
            $found = $part === '' ? $at : mb_strpos($text, $part, $at);

            if ($found === false) {
                return false;
            }

            $at = $found + mb_strlen($part);
        }

        return true;
    }

    /**
     * The pieces of a term between its «*», empty ones dropped: SQL joins them, each escaped, with «%» into one LIKE.
     *
     * @return list<string>
     */
    public static function parts(string $term): array
    {
        return array_values(array_filter(explode('*', trim($term)), static fn (string $part): bool => $part !== ''));
    }

    /**
     * D-1378: the one spelling a search compares — lower case, «µ» → «u», «Ω» → «ohm», no space between a number and what
     * follows it, a comma between digits a point. `assets/list-head.js` repeats it.
     */
    public static function canonical(string $text): string
    {
        $text = strtr(mb_strtolower($text), self::SIGNS);
        $text = (string) preg_replace('/(\d)[\s\x{00A0}\x{2009}\x{202F}]+(?=[^\d\s])/u', '$1', $text);

        return (string) preg_replace('/(?<=\d),(?=\d)/u', '.', $text);
    }

    /**
     * D-1378: the ways a stored text may spell one piece, for SQL, which cannot read it in {@see self::canonical()}: with and without
     * the space after a number, «u» after a number also as either micro sign, «ohm» after a number also as either ohm sign, a decimal
     * point also as a comma. The piece itself always comes first.
     *
     * @return list<string>
     */
    public static function spellings(string $part): array
    {
        $spellings = [''];
        $chars     = mb_str_split(self::canonical($part));
        $count     = count($chars);

        for ($i = 0; $i < $count; $i++) {
            $char      = $chars[$i];
            $nachZahl  = $i > 0 && ctype_digit($chars[$i - 1]);
            $optionen  = [$char];

            if ($char === 'o' && implode('', array_slice($chars, $i, 3)) === 'ohm') {
                // *«ohm» wherever it stands — «10kohm» is «10 kΩ»; no word of the model holds «ohm» otherwise.*
                $optionen = ['ohm', "\u{03A9}", "\u{2126}"];
                $i       += 2;
            } elseif ($char === '.' && $nachZahl && ctype_digit($chars[$i + 1] ?? '')) {
                $optionen[] = ',';
            } elseif ($char === 'u' && $nachZahl) {
                // *Only right after a number: «usb» stays «usb».*
                $optionen = ['u', "\u{00B5}", "\u{03BC}"];
            }

            if ($nachZahl && ! ctype_digit($char) && $char !== '.') {
                $optionen = [...$optionen, ...array_map(static fn (string $o): string => ' ' . $o, $optionen)];
            }

            $weiter = [];

            foreach ($spellings as $vorne) {
                foreach ($optionen as $option) {
                    $weiter[] = $vorne . $option;
                }
            }

            $spellings = array_slice($weiter, 0, self::MOST_SPELLINGS);
        }

        return array_values(array_unique([trim($part), ...$spellings]));
    }
}
