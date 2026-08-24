<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * The label roles that ship — **the seeded set, not the possible set** (D-151, D-196).
 *
 * ⚠️ **Roles are nodes, and this enum is not the role.** It names the handful the engine seeds
 * and must be able to find; an author adds more as ordinary nodes, and nothing here has to know.
 * That is possible only because D-044 made the role a **setting a renderer reads** rather than a
 * constant it hard-codes — so the name merely flows through and may be data.
 *
 * ⚠️ **`help` is the end of the fallback chain** (D-209). It was called `long` until the owner
 * struck the word: *`long` is gone, it is defined as `help`.*
 *
 * @see docs/NewConcept/40-i18n.md
 */
enum SeededRole: string
{
    /** What a field is called in a form. */
    case Form = 'form';

    /** What a column is called in a table — often shorter. */
    case Table = 'table';

    /** What an entry is called in a chooser. */
    case Select = 'select';

    /** A very short text — `Ω`, `St`, `Pos.` ⚠️ A **label**, not an icon (D-252). */
    case Symbol = 'symbol';

    /** The long description, which doubles as the tooltip, and ends the chain (D-209). */
    case Help = 'help';

    /**
     * ⚠️ **`symbol` defaults to not translatable** (D-261, D-262) — `Ω` is `Ω` everywhere, and
     * offering a translation field for it invites somebody to fill it wrongly. It is a default,
     * not a fact: a symbol that genuinely differs per locale can still be marked translatable.
     */
    public function translatableByDefault(): bool
    {
        return $this !== self::Symbol;
    }
}
