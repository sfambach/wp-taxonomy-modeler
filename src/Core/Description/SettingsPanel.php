<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * The settings of a node, grouped by what they are about (D-385) and inside a group by what they are for; switches apart from
 * fields and first, because two widths cannot share one grid.
 *
 * ⚠️ *One form around the whole panel when editing (D-392): the save button stands in the page head and reaches it by its id.*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class SettingsPanel implements Describes
{
    /**
     * @param list<array{group: string, heading: string, sections: list<array{heading: ?string, switches: list<SettingRow>, fields: list<SettingRow>}>}> $groups
     */
    public function __construct(
        public readonly array $groups,
        /** The form's id; empty: no form. */
        public readonly string $formId = '',
        public readonly string $action = '',
        public readonly ?Carried $carried = null,
    ) {
    }

    public function toArray(): array
    {
        $rows = static fn (array $list): array => array_map(static fn (SettingRow $r): array => $r->toArray(), $list);

        return [
            'settings' => array_map(static fn (array $g): array => [
                'group'    => $g['group'],
                'heading'  => $g['heading'],
                'sections' => array_map(static fn (array $s): array => ['heading' => $s['heading'], 'switches' => $rows($s['switches']), 'fields' => $rows($s['fields'])], $g['sections']),
            ], $this->groups),
            'form'    => $this->formId,
            'action'  => $this->action,
            'carried' => $this->carried?->toArray(),
        ];
    }
}
