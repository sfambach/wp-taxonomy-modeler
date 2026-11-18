<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * An opener of the shared choosing tree: the button with what the script needs, and the hidden field the choice goes into
 * ([D-815](../../../docs/NewConcept/90-decision-log.md), [D-821](../../../docs/NewConcept/90-decision-log.md)).
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class PickOpener implements Describes
{
    /**
     * @param list<int> $barred Not choosable here.
     * @param list<int> $open   Branches standing open when it opens (D-615).
     */
    public function __construct(
        public readonly string $field,
        public readonly string $form,
        public readonly ?int $chosen,
        public readonly ?int $initial,
        public readonly array $barred,
        public readonly array $open,
        public readonly string $title,
        /** What the button shows: the chosen name, or a symbol whose label is there for the reader only. */
        public readonly CurrentChoice|Icon $face,
        /** Opened as a dialog that names the chosen node, rather than as a bare icon button. */
        public readonly bool $showsName = false,
    ) {
    }

    public function toArray(): array
    {
        return [
            'pick_opener' => $this->field,
            'form'        => $this->form,
            'chosen'      => $this->chosen,
            'initial'     => $this->initial,
            'barred'      => $this->barred,
            'open'        => $this->open,
            'title'       => $this->title,
            'face'        => $this->face->toArray(),
            'shows_name'  => $this->showsName,
        ];
    }
}
