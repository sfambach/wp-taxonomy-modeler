<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

use Taxmod\Core\Renderer\Renderable;

/**
 * One record as a row of a record tree — its id, the record it hangs under, and the word it is shown with (D-1084).
 *
 * ⚠️ *A record is not a node ([D-1084](../../../docs/NewConcept/90-decision-log.md): «Lagerorte bleiben Sätze … keine Knoten im
 * Modell»), so it does not pretend to be one; it only answers what the chooser cell asks of anything it draws — an id and a label.*
 *
 * @see docs/NewConcept/90-decision-log.md D-1084
 */
final class RecordInTree implements Renderable
{
    public function __construct(
        public readonly int $id,
        /** The record this one hangs under, as its «Übergeordnet» field says; `null` where the field is empty. */
        public readonly ?int $parentId,
        private readonly string $word,
    ) {
    }

    public function label(): string
    {
        return $this->word;
    }

    public function content(): string
    {
        return '';
    }
}
