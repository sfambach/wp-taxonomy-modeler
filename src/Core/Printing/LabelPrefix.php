<?php declare(strict_types=1);

namespace Taxmod\Core\Printing;

/**
 * The fixed letter in front of the id on a label: what kind of thing the scanned code opens (D-1032).
 *
 * @see docs/NewConcept/90-decision-log.md D-1032
 */
enum LabelPrefix: string
{
    case Place = 'O';
    case Material = 'M';
    case Copy = 'E';
    case Box = 'K';

    public function code(int $id): string
    {
        return $this->value . '-' . $id;
    }
}
