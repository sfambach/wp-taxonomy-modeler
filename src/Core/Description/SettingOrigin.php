<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * Where a setting's winning value came from, as far as a reader needs to know it (D-266, D-689).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
enum SettingOrigin: string
{
    /** Written here, or nobody has said: nothing to mark. */
    case Unmarked = 'unmarked';
    /** The system chose, because what was there is not allowed here (D-688); the text says what it replaced. */
    case Automatic = 'automatic';
    /** Inherited, and the text names from where. */
    case InheritedFrom = 'inherited_from';
    /** Inherited from a link the walk could not name; the text is only the word «inherited». */
    case Inherited = 'inherited';
}
