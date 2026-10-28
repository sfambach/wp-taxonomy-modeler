<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Import\FetchingSource;

/**
 * How often the schedule fetches a module (D-981): one field, drawn by the modeller at the same place in every module tab
 * that names its source, stored once for all. Without his choice the module's own interval counts.
 *
 * @see docs/pakete/import/konzept.md §3.7
 */
final class FetchInterval
{
    public const OPTION = 'taxmod_import_intervals';
    public const FIELD = 'taxmod_fetch_interval';

    /** '' leaves the module's own interval. */
    private const SECONDS = ['' => 0, 'off' => 0, 'hourly' => HOUR_IN_SECONDS, 'fourtimesdaily' => 6 * HOUR_IN_SECONDS, 'twicedaily' => 12 * HOUR_IN_SECONDS, 'daily' => DAY_IN_SECONDS];

    /** A module that says 0 is not ready (no credentials) and stays off whatever he chose. */
    public static function of(FetchingSource $source): int
    {
        $own = $source->interval();
        $chosen = self::chosen($source->key());

        return $own <= 0 || $chosen === '' ? max(0, $own) : self::SECONDS[$chosen];
    }

    public static function save(string $sourceKey, string $choice): void
    {
        $choice = sanitize_key($choice);
        $all = (array) get_option(self::OPTION, []);

        if ($choice === '' || ! isset(self::SECONDS[$choice])) {
            unset($all[$sourceKey]);
        } else {
            $all[$sourceKey] = $choice;
        }

        update_option(self::OPTION, $all, false);
    }

    /** How often a source runs, in words — for a module that only points to another's interval (D-1008). */
    public static function describe(FetchingSource $source): string
    {
        $chosen = self::chosen($source->key());

        if ($chosen !== '') {
            return self::labels()[$chosen];
        }

        $seconds = max(0, $source->interval());

        return $seconds === 0 ? self::labels()['off'] : sprintf(
            /* translators: %d: hours */
            _n('every %d hour', 'every %d hours', max(1, intdiv($seconds, HOUR_IN_SECONDS)), 'taxmod'),
            max(1, intdiv($seconds, HOUR_IN_SECONDS))
        );
    }

    /** @return array<string, string> */
    private static function labels(): array
    {
        return [
            ''               => __('as the module says', 'taxmod'),
            'off'            => __('off (only by hand)', 'taxmod'),
            'hourly'         => __('hourly', 'taxmod'),
            'fourtimesdaily' => __('4× daily', 'taxmod'),
            'twicedaily'     => __('2× daily', 'taxmod'),
            'daily'          => __('daily', 'taxmod'),
        ];
    }

    /** The field as a form-table row; escaped HTML (CD-8). */
    public static function field(FetchingSource $source): string
    {
        $labels = self::labels();
        $options = '';

        foreach ($labels as $value => $label) {
            $options .= '<option value="' . esc_attr($value) . '"' . selected(self::chosen($source->key()), $value, false) . '>' . esc_html($label) . '</option>';
        }

        $now = self::of($source);
        $state = $now > 0
            /* translators: %s: hours */
            ? sprintf(__('now every %s h', 'taxmod'), rtrim(rtrim(number_format($now / HOUR_IN_SECONDS, 1, '.', ''), '0'), '.'))
            : __('now off', 'taxmod');

        return '<table class="form-table taxmod-fetch-interval" role="presentation"><tbody><tr><th scope="row"><label for="taxmod-fetch-interval">'
            . esc_html__('Fetch interval', 'taxmod') . '</label></th><td><select id="taxmod-fetch-interval" name="' . self::FIELD . '">' . $options . '</select> '
            . '<span class="description">' . esc_html($state . ' · ' . __('«Fetch all» on the inbox page fetches now.', 'taxmod')) . '</span></td></tr></tbody></table>';
    }

    private static function chosen(string $sourceKey): string
    {
        $chosen = (string) (((array) get_option(self::OPTION, []))[$sourceKey] ?? '');

        return isset(self::SECONDS[$chosen]) ? $chosen : '';
    }
}
