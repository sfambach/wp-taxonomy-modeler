<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * What a person enters into an input — the matter, never a tag (D-1429 A14).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
enum InputKind: string
{
    /** No renderer was chosen: one line, as it is. */
    case Unchosen = 'unchosen';
    case Line = 'line';
    case LongText = 'long_text';
    case Code = 'code';
    case Number = 'number';
    /** A number between `min` and `max`, shown with its value beside it. */
    case Range = 'range';
    case Colour = 'colour';
    case Email = 'email';
    /** Yes or no; unticked sends `0`. */
    case Flag = 'flag';
    /** Shown but not changeable here (`reason` says why); the stored value travels hidden. */
    case Fixed = 'fixed';
}
