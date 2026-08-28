<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * One piece of residue, ready to be shown and to be removed.
 *
 * ⚠️ **The words arrive translated and the shape is the renderer's** — the same division
 * {@see Control} and {@see Section} already draw. *`what` names a leftover in a sentence a person
 * reads, which is a text-domain matter (`AR-2`) and includes the counting: «3 rows» is not a number
 * the core may format, because how a number reads belongs to a locale.*
 *
 * ⚠️ **It carries a {@see Control} and a {@see Submission} rather than a URL or markup.** Removing
 * residue is an act at the boundary — capability, nonce, one change group — so the boundary states
 * *what may be done* and this only travels with it.
 *
 * @see docs/NewConcept/20-interaction.md
 */
final class ResidueEntry
{
    public function __construct(
        public readonly string $what,
        public readonly Control $act,
        public readonly Submission $submits,
    ) {
    }
}
