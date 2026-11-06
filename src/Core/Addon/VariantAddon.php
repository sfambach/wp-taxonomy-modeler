<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

/**
 * «Je Variante» on a field: the field is filled in each variant of a record, not in the record itself (D-1208). A variant
 * leaving it empty shows its parent's value; every field without the flag comes from the parent and is locked there.
 *
 * ```mermaid
 * flowchart LR
 *   M["material · size, controller"] -->|Variante von| V["variant · Farbe, Pins, Bilder"]
 *   F["edge with «Je Variante»"] -.-> V
 * ```
 *
 * ⚠️ *His word, 2026-11-06: «ob die Felder in den Kindern zu füllen sind, legen wir am Feld dann fest … Oder an der Connection
 * wahrscheinlich».* No settings of its own: being chosen is the whole statement.
 *
 * @see docs/pakete/varianten/plan.md
 */
final class VariantAddon implements Addon
{
    public const NAME = 'variant';

    public function name(): string
    {
        return self::NAME;
    }

    public function sites(): array
    {
        return [AddonSite::Edge];
    }

    public function requirements(): array
    {
        return [];
    }
}
