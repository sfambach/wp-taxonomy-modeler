<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * A dialog: its opener, a head, a body and a foot of buttons, opened and closed without a script (D-730).
 *
 * ⚠️ *Scaffolding (A15): head, body and foot are still drawn markup where the composite around them still builds markup;
 * they become descriptions with it.*
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class DialogBox implements Describes
{
    public function __construct(
        /** Unique on the page. */
        public readonly string $switch,
        public readonly Describes|string $opener,
        public readonly Describes|string $head,
        public readonly Describes|string $body,
        public readonly Describes|string $foot,
        public readonly string $openerClass = 'button taxmod-icon-button taxmod-dialog-open',
        public readonly string $ok = '',
        public readonly string $cancel = '',
        public readonly bool $open = false,
        /** The word of a tree button beside the opener; `null`: none. */
        public readonly ?string $tree = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'dialog'       => $this->switch,
            'opener'       => $this->opener instanceof Describes ? $this->opener->toArray() : $this->opener,
            'head'         => $this->head instanceof Describes ? $this->head->toArray() : $this->head,
            'body'         => $this->body instanceof Describes ? $this->body->toArray() : $this->body,
            'foot'         => $this->foot instanceof Describes ? $this->foot->toArray() : $this->foot,
            'opener_class' => $this->openerClass,
            'ok'           => $this->ok,
            'cancel'       => $this->cancel,
            'open'         => $this->open,
            'tree'         => $this->tree,
        ];
    }
}
