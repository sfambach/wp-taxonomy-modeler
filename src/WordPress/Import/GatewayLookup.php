<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Exception\ModelNotReadable;
use Taxmod\Core\Import\ExistingOrder;
use Taxmod\Core\Import\ModelLookup;
use Taxmod\Core\Import\OrderProposal;
use WP_Error;

/**
 * The model lookup a source gets while the owner is logged in: the gateway, with a refusal turned into an exception
 * (CD-10).
 *
 * @see docs/pakete/import/konzept.md §3.8
 */
final class GatewayLookup implements ModelLookup
{
    public function __construct(private readonly ModelGateway $gateway)
    {
    }

    public function findOrder(string $platform, string $orderNumber): ?ExistingOrder
    {
        return self::unwrap($this->gateway->findOrder($platform, $orderNumber));
    }

    public function findLoose(OrderProposal $proposal): ?ExistingOrder
    {
        return self::unwrap($this->gateway->findLoose($proposal));
    }

    private static function unwrap(ExistingOrder|WP_Error|null $found): ?ExistingOrder
    {
        if ($found instanceof WP_Error) {
            throw ModelNotReadable::because($found->get_error_message());
        }

        return $found;
    }
}
