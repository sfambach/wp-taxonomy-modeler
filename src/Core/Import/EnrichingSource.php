<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * A source that completes the proposals of another source — JLCPCB adds the prices its mails never name. It creates
 * nothing; the inbox hands it each open proposal of a platform it names.
 *
 * @see docs/pakete/import/konzept.md §2
 */
interface EnrichingSource extends ImportSource
{
    /** @return list<string> platforms whose proposals it completes, as the proposals name them */
    public function enriches(): array;

    /** The completed proposal, or null when there is nothing to add. */
    public function enrich(OrderProposal $proposal): ?OrderProposal;
}
