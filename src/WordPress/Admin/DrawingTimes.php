<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\WordPress\Persistence\Query;

/**
 * What one page took to draw, part by part: milliseconds and database statements — shown in the developer mode (Ladezeit S0).
 *
 * His word 2026-11-17: «besonders das initiale Laden dauert sehr lange». The architecture thread's condition: every step of the
 * package states this line before and after.
 *
 * @see docs/pakete/ladezeit/konzept.md
 */
final class DrawingTimes
{
    /** The developer-mode switch that shows the line. */
    public const OPTION = 'taxmod_dev_timing';

    /** @var array<string, array{ms: float, queries: int}> */
    private array $parts = [];

    /**
     * @template T
     * @param  callable(): T $work
     * @return T
     */
    public function measure(string $part, callable $work): mixed
    {
        $start   = hrtime(true);
        $queries = Query::asked();

        try {
            return $work();
        } finally {
            $this->parts[$part] = [
                'ms'      => ($this->parts[$part]['ms'] ?? 0.0) + (hrtime(true) - $start) / 1e6,
                'queries' => ($this->parts[$part]['queries'] ?? 0) + Query::asked() - $queries,
            ];
        }
    }

    /** The line under the page, or nothing when the developer mode does not ask for it. `$bytes` is the size of what was drawn. */
    public function line(int $bytes): string
    {
        if (! SettingsScreen::developerShows(self::OPTION)) {
            return '';
        }

        $total = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (microtime(true) - (float) $_SERVER['REQUEST_TIME_FLOAT']) * 1000 : 0.0;
        $words = [];

        foreach ($this->parts as $part => $took) {
            /* translators: 1: part of the page, 2: milliseconds, 3: database statements */
            $words[] = sprintf(__('%1$s %2$d ms / %3$d queries', 'taxmod'), $part, (int) round($took['ms']), $took['queries']);
        }

        /* translators: 1: the parts, 2: milliseconds since the request began, 3: database statements in all, 4: kilobytes drawn */
        $text = sprintf(__('Drawn: %1$s · in all %2$d ms, %3$d queries, %4$d KB', 'taxmod'), implode(' · ', $words), (int) round($total), Query::asked(), (int) round($bytes / 1024));

        return '<p class="description taxmod-drawing-times">' . esc_html($text) . '</p>';
    }
}
