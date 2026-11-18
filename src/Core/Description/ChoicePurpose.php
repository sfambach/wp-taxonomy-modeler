<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * What a choice is for, where that changes how it behaves on the page — the matter, never a CSS class (D-1429 A14).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
enum ChoicePurpose: string
{
    /** The value of a field. */
    case Value = 'value';
    /** A colour from a palette: shown with a patch of the chosen one. */
    case Colour = 'colour';
    /** A record a summary field points at. */
    case Summary = 'summary';
    /** The next entry of a list of switched-on things, added by its «+». */
    case Candidate = 'candidate';
    /** The next value of a list of values; its own name travels with it for the script that adds it. */
    case ValueCandidate = 'value_candidate';
    /** The next function of an addon list. */
    case AddonCandidate = 'addon_candidate';
    /** One row of a list of references. */
    case ReferenceRow = 'reference_row';
}
