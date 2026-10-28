<?php declare(strict_types=1);

namespace Taxmod\Core\Import\Mail;

use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;

/**
 * A reader with the kinds of mail he unticked left out (D-1016): such a mail is still recognised as the shop's, but
 * brings nothing — it counts as «no receipt», so the Mail module puts it away instead of leaving it unrecognised.
 *
 * @see docs/pakete/import/module-schnitt.md §2a
 */
final class KindFilter implements MultiOrderMailParser
{
    /** @param list<ProposalKind> $unwanted */
    public function __construct(private readonly OrderMailParser $reader, private readonly array $unwanted)
    {
    }

    public function key(): string
    {
        return $this->reader->key();
    }

    public function shopName(): string
    {
        return $this->reader->shopName();
    }

    public function recognises(MailMessage $mail): bool
    {
        return $this->reader->recognises($mail);
    }

    public function parse(MailMessage $mail): OrderProposal
    {
        return $this->parseAll($mail)[0];
    }

    public function parseAll(MailMessage $mail): array
    {
        $all = $this->reader instanceof MultiOrderMailParser ? $this->reader->parseAll($mail) : [$this->reader->parse($mail)];
        $wanted = array_values(array_filter($all, fn (OrderProposal $proposal): bool => ! in_array($proposal->kind, $this->unwanted, true)));

        return $wanted !== [] ? $wanted : [new OrderProposal($all[0]->platform, $all[0]->orderNumber, ProposalKind::NoReceipt)];
    }

    public function kinds(): array
    {
        return $this->reader->kinds();
    }

    /** The choice is part of the version: unticking or ticking a kind lets the Mail module read «Nicht erkannt» again. */
    public function version(): string
    {
        $unwanted = array_map(static fn (ProposalKind $kind): string => $kind->value, $this->unwanted);
        sort($unwanted);

        return $this->reader->version() . ($unwanted === [] ? '' : '-' . implode('.', $unwanted));
    }
}
