<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * A proposal as a source hands it to the inbox, with what only the source and the review page read: sender and
 * subject of a mail, its UID — never a secret (D-951).
 *
 * @see docs/pakete/import/konzept.md §3.2
 */
final class IncomingProposal
{
    /** @param array<string, scalar|null> $evidence */
    public function __construct(
        public readonly OrderProposal $proposal,
        public readonly array $evidence = [],
    ) {
    }
}
