<?php declare(strict_types=1);

namespace Taxmod\Core\Exception;

use Taxmod\Core\Addon\Complaint;

/**
 * The validators refused a save — nothing was written ([D-760](../../../docs/NewConcept/90-decision-log.md)), and every
 * complaint travels on with its place, so none is lost on the way to the page (renderer plan step 5,
 * [D-632](../../../docs/NewConcept/90-decision-log.md)).
 *
 * @see docs/pakete/renderer-ereignisse/plan.md
 */
final class RefusedByValidators extends DomainError
{
    /** @param list<Complaint> $complaints */
    public function __construct(string $message, public readonly array $complaints)
    {
        parent::__construct($message);
    }
}
