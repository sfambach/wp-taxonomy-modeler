<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

/**
 * The value a person has chosen but not saved yet, as `live.js` sends it when it fetches an element that follows a field (D-1551).
 *
 * A drawer of a follower reads it here before the stored value, so the part it draws fits the choice on the screen. Only a
 * drawing reads it — nothing is written on a GET; the page's own capability check has run before any drawer.
 *
 * @see docs/NewConcept/90-decision-log.md D-1551
 */
final class ChosenValue
{
    public const PARAMETER = 'taxmod_chosen';

    /** @return string|list<string>|null null when the field was not sent; a list for a field of several values. */
    public static function of(string $field): string|array|null
    {
        $all = self::all();

        return array_key_exists($field, $all) ? $all[$field] : null;
    }

    /** @return array<string, string|list<string>> */
    public static function all(): array
    {
        $raw = isset($_GET[self::PARAMETER]) && is_string($_GET[self::PARAMETER]) ? wp_unslash($_GET[self::PARAMETER]) : '';
        $sent = $raw === '' ? null : json_decode($raw, true);

        if (! is_array($sent)) {
            return [];
        }

        $values = [];

        foreach ($sent as $field => $value) {
            if (! is_string($field)) {
                continue;
            }

            if (is_array($value)) {
                // mixed: decoded JSON, any shape; only scalars are kept.
                $values[$field] = array_values(array_map(static fn (mixed $one): string => sanitize_text_field(is_scalar($one) ? (string) $one : ''), $value));
            } elseif (is_scalar($value)) {
                $values[$field] = sanitize_text_field((string) $value);
            }
        }

        return $values;
    }
}
