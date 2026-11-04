<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

use Taxmod\Core\Model\NodeClass\Attribut;
use Taxmod\Core\Model\NodeClass\FieldSource;

/**
 * A key figure on a node: its records (and those of every node below it) counted or summed per month, shown as a tile
 * (D-1149). Chosen in the node's mask like any other add-on function, so a new figure is data, not code.
 *
 * ```mermaid
 * flowchart LR
 *   N["node + subtree"] --> F["filter: field = value"]
 *   F --> D["month of the date field"]
 *   D --> M["count or sum of a field"]
 *   M --> T["tile: this month · previous · 12-month bars"]
 * ```
 *
 * @see docs/pakete/kacheln/konzept.md
 */
final class MetricAddon implements Addon
{
    public const NAME = 'metric';

    /** The tile's heading; empty means the node's name. */
    #[Attribut]
    public string $title = '';

    #[Attribut]
    public MetricMeasure $measure = MetricMeasure::Count;

    /** The field summed for {@see MetricMeasure::Sum}; a part (an amount of money) is summed through its first number. */
    #[Attribut(refersTo: 'relation', fieldsFrom: FieldSource::Owner)]
    public ?int $value_field = null;

    /** The date that puts a record into its month; a reference is followed to the earliest date of the record it names. Empty: when the record was created. */
    #[Attribut(refersTo: 'relation', fieldsFrom: FieldSource::Owner)]
    public ?int $date_field = null;

    /** Only records whose field holds the value below — a text, a number or the id of a chosen record or constant. */
    #[Attribut(refersTo: 'relation', fieldsFrom: FieldSource::Owner)]
    public ?int $filter_field = null;

    #[Attribut]
    public string $filter_value = '';

    /** How many months the bars show, the current one included (1–36). */
    #[Attribut]
    public int $months = 12;

    public function name(): string
    {
        return self::NAME;
    }

    public function sites(): array
    {
        return [AddonSite::Node];
    }

    public function requirements(): array
    {
        return [];
    }
}
