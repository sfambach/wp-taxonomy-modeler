<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * What a source may ask the model while the owner is logged in — is this order there? The boundary answers through the
 * abilities as that user (D-936); a scheduled run has no user and gets no lookup.
 *
 * @see docs/pakete/import/konzept.md §3.8
 */
interface ModelLookup
{
    public function findOrder(string $platform, string $orderNumber): ?ExistingOrder;

    /** The order a proposal without a number belongs to, by seller or title (LooseMatch). */
    public function findLoose(OrderProposal $proposal): ?ExistingOrder;
}
