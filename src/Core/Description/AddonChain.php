<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * The extra functions at a node or edge: the inherited ones greyed above, the own ones as an ordered chain,
 * one choice to add another, and a template per choosable function.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class AddonChain implements Describes
{
    /**
     * @param list<string>     $inherited Titles of the functions shown from above, not submitted.
     * @param list<AddonEntry> $entries
     * @param list<AddonEntry> $templates One per choosable function, addressed as a new link.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $form,
        public readonly array $inherited,
        public readonly string $inheritedWord,
        public readonly array $entries,
        public readonly array $templates,
        public readonly Choosing $candidates,
        public readonly AddChosen $add,
    ) {
    }

    public function toArray(): array
    {
        return [
            'addon_chain'    => $this->name,
            'form'           => $this->form,
            'inherited'      => $this->inherited,
            'inherited_word' => $this->inheritedWord,
            'entries'        => array_map(static fn (AddonEntry $e): array => $e->toArray(), $this->entries),
            'candidates'     => $this->candidates->toArray(),
            'add'            => $this->add->toArray(),
            'templates'      => array_map(static fn (AddonEntry $e): array => $e->toArray(), $this->templates),
        ];
    }
}
