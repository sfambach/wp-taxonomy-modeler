<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

/**
 * Words shown as they are — a dialog's heading, the text beside an opener's symbol. The edge escapes them.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class Words implements Describes
{
    public function __construct(public readonly string $text)
    {
    }

    public function toArray(): array
    {
        return ['words' => $this->text];
    }
}
