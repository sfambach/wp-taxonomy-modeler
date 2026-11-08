<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * Why a parent link «Variante von» may not stand (D-1208): one parent, no variant of a variant.
 *
 * @see docs/pakete/varianten/plan.md
 */
enum VariantComplaint: string
{
    case OfItself = 'of_itself';
    case ParentIsVariant = 'parent_is_variant';
    case HasVariants = 'has_variants';
}
