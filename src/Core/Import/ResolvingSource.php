<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * A source that wants to hear when the owner settled one of its proposals — tax_mail moves the mail into
 * «Importiert» or «Verworfen» then.
 *
 * @see docs/pakete/import/konzept.md §3.5
 */
interface ResolvingSource extends ImportSource
{
    /** @param array<string, scalar|null> $evidence what the source attached to the proposal */
    public function resolved(array $evidence, InboxStatus $status): void;
}
