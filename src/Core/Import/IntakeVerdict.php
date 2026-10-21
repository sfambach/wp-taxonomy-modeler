<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * What the inbox does with an incoming proposal.
 *
 * @see docs/pakete/import/konzept.md §4
 */
enum IntakeVerdict: string
{
    /** An order the model does not have — open for review. */
    case NewOrder = 'new_order';
    /** Status of an order in the model — open for review as an update. */
    case Update = 'update';
    /** Nothing to do: the order is there and the proposal adds nothing, or there is nothing to attach it to. */
    case Nothing = 'nothing';
    /** Another active source is responsible (D-953, S4). */
    case NotResponsible = 'not_responsible';
}
