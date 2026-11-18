<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * What a media address turned out to be, so a drawer knows how to show its link.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
enum MediaLook: string
{
    /** A document of the private vault, readable here (`url`). */
    case Private = 'private';
    /** A document of the private vault without a reading address here: its name only. */
    case PrivateUnreadable = 'private_unreadable';
    /** A file of the media library that is gone: its name only. */
    case Missing = 'missing';
    /** An image of the media library: its thumbnail. */
    case Image = 'image';
    /** Any other file of the media library. */
    case File = 'file';
    /** An address outside the media library; `state` says what the post there is, if it is one of ours. */
    case Address = 'address';
}
