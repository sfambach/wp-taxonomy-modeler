<?php declare(strict_types=1);

namespace Taxmod\Core\Renderer;

/**
 * One label role as the labels panel needs it — **resolved, so the renderer fetches nothing**.
 *
 * ⚠️ **`stored` and `shown` are two different facts and the panel needs both**
 * ([D-020](../../../docs/NewConcept/90-decision-log.md)). `stored` is what is written **here, in this
 * locale**, and is `null` where nothing is; `shown` is what the fallback chain answers — another
 * locale, the `help` role, or in the end the node's own name. *An empty field with the resolved text
 * as its placeholder says «nothing is stored and something else answers»; an empty field alone says
 * «this thing has no name», which is never true.*
 *
 * ⚠️ **`isLong` and not a list of role names.** The panel lays words out on one line and sentences on
 * rows of their own, and *which* roles are words is a property of the role rather than of the panel —
 * so a sixth role added tomorrow lands somewhere sensible instead of silently joining the word row
 * (`CD-9`: no special-casing by name).
 *
 * @see docs/NewConcept/40-i18n.md
 */
final class LabelSlot
{
    /**
     * @param string      $role         The role's key — shown as the field's own small heading.
     * @param string      $shown        What this locale currently resolves to, fallback included.
     * @param string|null $stored       What is written here in this locale, or null for nothing.
     * @param string      $fieldName    Where the entry is submitted.
     * @param bool        $isLong       Whether it holds a sentence rather than a word.
     * @param bool        $translatable Whether a locale row is expected at all.
     * @param string      $note         The already-translated remark shown where it is not.
     *
     * ⚠️ **`translatable` carries [D-261](../../../docs/NewConcept/90-decision-log.md)'s default and
     * is a *default*, not a prohibition:** `symbol` is `Ω` everywhere, so a locale row is usually
     * pointless — and `Stück`/`pc` is the case that proves it must stay possible.
     */
    public function __construct(
        public readonly string $role,
        public readonly string $shown,
        public readonly ?string $stored,
        public readonly string $fieldName,
        public readonly bool $isLong = false,
        public readonly bool $translatable = true,
        public readonly string $note = '',
    ) {
    }
}
