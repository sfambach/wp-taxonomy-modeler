<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * What one run of a fetching source brought, and where the next run starts.
 *
 * @see docs/pakete/import/konzept.md §2
 */
final class FetchBatch
{
    /** @param list<IncomingProposal> $proposals */
    public function __construct(
        public readonly array $proposals,
        public readonly ?string $cursor,
    ) {
    }
}
