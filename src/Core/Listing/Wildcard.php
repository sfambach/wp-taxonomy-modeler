<?php declare(strict_types=1);

namespace Taxmod\Core\Listing;

/**
 * The one rule for what a search or a filter term matches (D-1357): «contains», ignoring case, and «*» stands for any
 * characters, none included. Nothing else is special — «?», «%» and «_» are what they are. His word 2026-11-14: «Als
 * Wildcard hätte ich gerne das Sternchen.» `assets/list-head.js` repeats this rule for the rows a page drew; SQL takes it
 * through {@see self::parts()}.
 *
 * @see docs/NewConcept/90-decision-log.md D-1357
 */
final class Wildcard
{
    /** Whether `$text` holds `$term`, its pieces between «*» in this order. An empty term matches everything. */
    public static function matches(string $text, string $term): bool
    {
        $at = 0;

        foreach (self::parts($term) as $part) {
            $found = mb_stripos($text, $part, $at);

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
}
