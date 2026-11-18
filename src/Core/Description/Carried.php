<?php declare(strict_types=1);

namespace Taxmod\Core\Description;

use Taxmod\Core\Renderer\Submission;

/**
 * Values that travel unseen with a form: what the edge needs back to know which act and which thing it was.
 *
 * @see docs/pakete/renderer-ereignisse/umsetzung-2026-11.md
 */
final class Carried implements Describes
{
    /** @param array<string, string> $values name ⇒ value */
    public function __construct(
        public readonly array $values,
        /** The form they belong to when they stand outside it. */
        public readonly string $form = '',
    ) {
    }

    public static function of(Submission $submits, string $form = ''): self
    {
        return new self($submits->hidden, $form);
    }

    public function toArray(): array
    {
        return ['carried' => $this->values, 'form' => $this->form];
    }
}
