<?php declare(strict_types=1);

namespace Taxmod\Core\Addon;

/**
 * «Variante von» on a reference field: the record it names is this record's parent, from which every field without
 * «Je Variante» is read (D-1208). One parent, no variant of a variant — {@see \Taxmod\Core\Model\Variants::complaints()}.
 *
 * ⚠️ *Chosen on the edge, not found by the field's name (CD «no special-casing by display name»): which reference is the
 * parent link is data.*
 *
 * @see docs/pakete/varianten/plan.md
 */
final class VariantOfAddon implements Addon
{
    public const NAME = 'variant_of';

    public function name(): string
    {
        return self::NAME;
    }

    public function sites(): array
    {
        return [AddonSite::RecordEdge];
    }

    public function requirements(): array
    {
        return [];
    }
}
