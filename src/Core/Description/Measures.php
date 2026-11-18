<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * Whether a description shows anything once drawn — the one question the core asks the edge (A20).
 *
 * ⚠️ *Measured 18.11.: a part whose table shows nothing (D-954) takes no column, no words (D-889) and no count (D-825); those are
 * decisions of the core, and only the edge can tell, because only the edge draws. Nothing else goes through here; the callers are
 * counted by `core-html-ratchet-check`.*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
interface Measures
{
    public function shows(Describes $piece): bool;
}
