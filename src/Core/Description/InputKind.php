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
    /** D-1501: a decimal typed in the site's notation (1,5), read back by `DecimalText::typed`. */
    case Decimal = 'decimal';
    /** D-1501: a secret such as a password or a key; never sent back filled in. */
    case Secret = 'secret';
    /** A number between `min` and `max`, shown with its value beside it. */
    case Range = 'range';
    case Colour = 'colour';
    case Email = 'email';
    /** Yes or no; unticked sends `0`. */
    case Flag = 'flag';
    /** Yes or no as a switch, the look the owner chose for it. */
    case Switch = 'switch';
    /** A point in time to the precision in `note`: year, month, date, time, datetime, any. */
    case Moment = 'moment';
    /** Shown but not changeable here (`reason` says why); the stored value travels hidden. */
    case Fixed = 'fixed';
}
