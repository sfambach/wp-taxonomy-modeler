<?php declare(strict_types=1);

namespace Taxmod\WordPress\Admin;

use Taxmod\Core\Addon\AddonRegistry;
use Taxmod\Core\Addon\ChosenAddon;
use Taxmod\Core\Addon\MetricAddon;
use Taxmod\Core\Addon\MetricMeasure;
use Taxmod\Core\Addon\ShippedAddons;
use Taxmod\Core\Converter\ShippedConverters;
use Taxmod\Core\Model\Node;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\SettingsResolver;
use Taxmod\Core\Tile\MonthlySeries;
use Taxmod\Core\Tile\Period;
use Taxmod\Core\Tile\PeriodSpan;
use Taxmod\Core\Tile\Tile;
use Taxmod\Core\Tile\TileGrid;
use Taxmod\Core\Tile\TileTone;
use Taxmod\WordPress\Persistence\Ancestry;
use Taxmod\WordPress\Persistence\MetricSums;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingsRepository;

/**
 * The tiles of every screen of the modeller and its modules (D-1149): one grid for the tiles a module computes itself, and
 * the key figures chosen as the add-on function «Kennzahl» on any node, each counted in one query. Kept apart from
 * {@see ListPage} on purpose: a list and a row of tiles are two building blocks (design 7.7).
 *
 * ```mermaid
 * flowchart LR
 *   A["add-on «metric» on nodes"] --> Q["one query per figure: month, count, sum"]
 *   Q --> S["MonthlySeries"] --> T["Tile"]
 *   M["module tiles"] --> T
 *   T --> G["TileGrid · one look"]
 * ```
 *
 * @see docs/pakete/kacheln/konzept.md
 */
final class Tiles
{
    /** @param list<Tile> $tiles */
    public static function grid(array $tiles, string $heading = ''): string
    {
        // *The core escapes as HTML; what a link may point to (no `javascript:`) is WordPress's to say, so it is said here.*
        $safe = array_map(static fn (Tile $t): Tile => $t->url === '' ? $t : new Tile($t->label, esc_url_raw($t->url), $t->figure, $t->lines, $t->tone, $t->bars, $t->barLabels, $t->trend, $t->actions), $tiles);

        return TileGrid::of($safe, $heading);
    }

    /** A tile with a count: «0» is the shrunk empty form (design 6.3), null means the tile has no figure. */
    public static function counted(string $label, ?int $count, string $url, array $lines = [], bool $alert = false): Tile
    {
        $tone = $count === 0 ? TileTone::Empty : ($alert ? TileTone::Alert : TileTone::Normal);

        return new Tile($label, $url, $count === null ? null : number_format_i18n($count), $lines, $tone);
    }

    /**
     * The key figures as tiles — of the whole model, or only of the nodes given and every node below them; per month, or
     * for one period of the period view against the one before it (D-1167).
     *
     * @param list<int>|null $under
     * @return list<Tile>
     */
    public static function metrics(?array $under = null, ?Period $period = null): array
    {
        $tiles = [];

        foreach (self::chosenMetrics($under) as [$node, $metric]) {
            try {
                $tiles[] = $period === null ? self::metricTile($node, $metric) : self::periodTile($node, $metric, $period);
            } catch (\RuntimeException $e) {
                // *A figure whose query fails says so on its tile; the others still stand.*
                $tiles[] = new Tile(self::titleOf($node, $metric), self::nodeUrl($node->id), '–', [$e->getMessage()], TileTone::Alert);
            }
        }

        return $tiles;
    }

    /** The key figures as a grid with the heading «Kennzahlen», or nothing when none is chosen. */
    public static function metricsGrid(?array $under = null, ?Period $period = null): string
    {
        return self::grid(self::metrics($under, $period), __('Key figures', 'taxmod'));
    }

    /** The query keys of the period view: which span, and a day inside the period. */
    public const SPAN = 'span';
    public const DAY  = 'day';

    /** The period the request asks for; today's day when it names none or a wrong one. */
    public static function periodFromRequest(PeriodSpan $default = PeriodSpan::Day): Period
    {
        $span = PeriodSpan::tryFrom(sanitize_key((string) ($_GET[self::SPAN] ?? ''))) ?? $default;
        $day  = sanitize_text_field(wp_unslash((string) ($_GET[self::DAY] ?? '')));

        try {
            return Period::of($span, $day);
        } catch (\InvalidArgumentException) {
            return Period::of($span, current_time('Y-m-d'));
        }
    }

    /**
     * The switch of the period view (D-1167): day, month or year, back, today, forward — plain links, so every view has its
     * own address and the browser's back button works.
     */
    public static function periodBar(Period $period, string $base): string
    {
        $link = static fn (PeriodSpan $span, string $day): string => esc_url(add_query_arg([self::SPAN => $span->value, self::DAY => $day], $base));
        $html = TileGrid::style() . '<nav class="taxmod-period" aria-label="' . esc_attr__('Period', 'taxmod') . '">';

        $words = ['day' => __('Day', 'taxmod'), 'month' => __('Month', 'taxmod'), 'year' => __('Year', 'taxmod')];

        foreach (PeriodSpan::cases() as $span) {
            $html .= '<a class="button' . ($span === $period->span ? ' is-chosen' : '') . '" href="' . $link($span, $period->start()) . '"'
                . ($span === $period->span ? ' aria-current="true"' : '') . '>' . esc_html($words[$span->value]) . '</a>';
        }

        $html .= '<a class="button" href="' . $link($period->span, $period->previous()->start()) . '" aria-label="' . esc_attr__('Earlier', 'taxmod') . '">‹</a>'
            . '<span class="taxmod-period-name">' . esc_html(self::periodName($period)) . '</span>'
            . '<a class="button" href="' . $link($period->span, $period->next()->start()) . '" aria-label="' . esc_attr__('Later', 'taxmod') . '">›</a>'
            . '<a class="button" href="' . $link($period->span, current_time('Y-m-d')) . '">' . esc_html__('Today', 'taxmod') . '</a>';

        return $html . '</nav>';
    }

    /** What a period is called on a tile, a heading or a step of its chart. */
    public static function periodName(Period $period): string
    {
        $at = (int) strtotime($period->start() . ' 00:00:00 UTC');

        return match ($period->span) {
            PeriodSpan::Day   => wp_date(get_option('date_format') ?: 'Y-m-d', $at, new \DateTimeZone('UTC')),
            PeriodSpan::Month => wp_date('F Y', $at, new \DateTimeZone('UTC')),
            PeriodSpan::Year  => wp_date('Y', $at, new \DateTimeZone('UTC')),
        };
    }

    /** The label of one step of a period's chart: a day «4.11.», a month «Nov». */
    public static function stepName(Period $period, string $step): string
    {
        $at = (int) strtotime(($period->span === PeriodSpan::Year ? $step . '-01' : $step) . ' 00:00:00 UTC');

        return wp_date($period->span === PeriodSpan::Year ? 'M' : 'j.n.', $at, new \DateTimeZone('UTC'));
    }

    /**
     * Every chosen key figure: the add-on rows named `addons` at nodes, their nodes read once, each function at most once
     * even where a child inherits it.
     *
     * @param list<int>|null $under
     * @return list<array{0: Node, 1: ChosenAddon}>
     */
    private static function chosenMetrics(?array $under): array
    {
        $settings = new WpdbSettingsRepository();
        $nodeIds  = [];

        foreach ($settings->valuesNamed(AddonRegistry::ATTRIBUTE) as $row) {
            if ($row->aktiv && $row->relationId === null && $row->nodeId !== null) {
                $nodeIds[$row->nodeId] = $row->nodeId;
            }
        }

        if ($nodeIds === []) {
            return [];
        }

        if ($under !== null) {
            $inside  = array_flip(array_map('intval', Ancestry::descendantIds(array_values(array_map('intval', $under)))));
            $nodeIds = array_filter($nodeIds, static fn (int $id): bool => isset($inside[$id]) || in_array($id, $under, true));
        }

        $nodes    = (new WpdbNodeRepository())->byIds(array_values($nodeIds));
        $resolver = new SettingsResolver(
            $settings,
            new WpdbNodeRepository(),
            ShippedRenderers::registry(),
            ShippedConverters::registry(),
            ShippedAddons::registry(),
            new WpdbRelationRepository()
        );
        $resolver->preload(array_values($nodes));

        $found = [];

        foreach ($nodes as $node) {
            foreach ($resolver->addonsAt($node) as $chosen) {
                if ($chosen->klasse === MetricAddon::class && ! isset($found[$chosen->objectId])) {
                    $found[$chosen->objectId] = [$node, $chosen];
                }
            }
        }

        return array_values($found);
    }

    private static function metricTile(Node $node, ChosenAddon $metric): Tile
    {
        $months  = (int) (($metric->settings['months'] ?? null)?->text ?? 12) ?: 12;
        $current = current_time('Y-m');
        $start   = gmdate('Y-m-01', (int) strtotime($current . '-01 -' . (max(1, min(36, $months)) - 1) . ' months'));
        $series  = MonthlySeries::of(MetricSums::perStep($node, $metric, '%Y-%m', $start, null), $current, $months);
        $sum     = MetricMeasure::tryFrom((string) (($metric->settings['measure'] ?? null)?->text ?? '')) === MetricMeasure::Sum;
        $format  = static fn (float $v): string => number_format_i18n($v, $sum && fmod($v, 1.0) !== 0.0 ? 2 : 0);
        $lines   = [];

        if ($series->previous() !== null) {
            $lines[] = sprintf(__('Previous month: %s', 'taxmod'), $format($series->previous()));
        }

        if ($series->averageBefore() !== null) {
            /* translators: 1: number of months, 2: the mean */
            $lines[] = sprintf(__('Mean of %1$d months: %2$s', 'taxmod'), $months - 1, $format($series->averageBefore()));
        }

        $labels = array_map(
            static fn (string $month, float $value): string => wp_date('M Y', (int) strtotime($month . '-01')) . ': ' . $format($value),
            array_keys($series->values()),
            array_values($series->values())
        );

        return new Tile(
            self::titleOf($node, $metric),
            self::nodeUrl($node->id),
            $format($series->current()),
            $lines,
            $series->total() == 0 ? TileTone::Empty : TileTone::Normal,
            array_values($series->values()),
            $labels,
            [1 => '▲', 0 => '', -1 => '▼'][$series->direction()] . ' ' . __('this month', 'taxmod'),
        );
    }

    /** D-1167: the figure of one period, the one before it as a line, and its steps as bars. */
    private static function periodTile(Node $node, ChosenAddon $metric, Period $period): Tile
    {
        $before = $period->previous();
        $from   = min($before->start(), $period->chartStart());
        $days   = MetricSums::perStep($node, $metric, '%Y-%m-%d', $from, $period->end());
        $sum    = MetricMeasure::tryFrom((string) (($metric->settings['measure'] ?? null)?->text ?? '')) === MetricMeasure::Sum;
        $format = static fn (float $v): string => number_format_i18n($v, $sum && fmod($v, 1.0) !== 0.0 ? 2 : 0);
        $now    = 0.0;
        $then   = 0.0;
        $steps  = array_fill_keys($period->steps(), 0.0);

        foreach ($days as $day => $value) {
            $now  += $period->contains($day) ? $value : 0.0;
            $then += $before->contains($day) ? $value : 0.0;

            if (isset($steps[$period->stepOf($day)])) {
                $steps[$period->stepOf($day)] += $value;
            }
        }

        $labels = array_map(static fn (string $step, float $v): string => self::stepName($period, $step) . ': ' . $format($v), array_keys($steps), array_values($steps));
        $trend  = $now <=> $then;

        return new Tile(
            self::titleOf($node, $metric),
            self::nodeUrl($node->id),
            $format($now),
            [sprintf(__('Before: %s', 'taxmod'), $format($then))],
            $now == 0 && $then == 0 ? TileTone::Empty : TileTone::Normal,
            array_values($steps),
            $labels,
            [1 => '▲', 0 => '', -1 => '▼'][$trend] . ' ' . self::periodName($period),
        );
    }

    private static function titleOf(Node $node, ChosenAddon $metric): string
    {
        $title = trim((string) (($metric->settings['title'] ?? null)?->text ?? ''));

        return $title === '' ? $node->name : $title;
    }

    private static function nodeUrl(int $nodeId): string
    {
        return add_query_arg(['page' => 'taxmod', 'taxmod_node' => $nodeId], admin_url('admin.php'));
    }
}
