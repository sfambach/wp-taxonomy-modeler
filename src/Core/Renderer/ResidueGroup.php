<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * One source of residue — what it is, why it exists, and what is lying there.
 *
 * ⚠️ **Three of these and no more, because [D-247](../../../docs/NewConcept/90-decision-log.md)
 * names three**: *[D-123](../../../docs/NewConcept/90-decision-log.md)'s two-stage deletion,
 * [D-156](../../../docs/NewConcept/90-decision-log.md)'s orphaned overrides,
 * [D-159](../../../docs/NewConcept/90-decision-log.md)'s values whose relation is gone.* **Each was
 * decided as *leave it alone rather than tidy it silently*** — so a group carries the reason with
 * it: a person clicking «remove» is undoing a deliberate non-decision, not fixing a bug.
 *
 * ⚠️ **`whenEmpty` exists so that nothing to tidy reads as good news.** *An empty table says «this
 * screen is broken or I do not understand it»; a sentence says «there is nothing here», which is
 * what a repair surface should say most of the time.*
 *
 * @see docs/NewConcept/20-interaction.md
 */
final class ResidueGroup
{
    /**
     * @param string             $title     Translated heading for this source.
     * @param string             $explains  Translated — why this residue exists at all.
     * @param string             $whenEmpty Translated — what to read when there is nothing.
     * @param list<ResidueEntry> $entries   What is lying there, in the order it was measured.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $explains,
        public readonly string $whenEmpty,
        public readonly array $entries = [],
    ) {
    }
}
