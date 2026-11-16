<?php declare(strict_types=1);

namespace Taxmod\Core\Import\Mail;

use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;

/**
 * A carrier's parcel notice (GLS, DPD, DHL, Hermes): no shop order, only a parcel number and whether it is under way or
 * delivered. The modeller gives it to the order carrying that number (D-1020); it never creates an order (D-1017). A
 * carrier writes how it finds the kind and the number; the proposal is drawn here once (plan-dubletten row 9).
 *
 * @see docs/pakete/import/module-schnitt.md
 */
abstract class ParcelNoticeMailParser extends ShopMailParser
{
    /** @param list<string> $domains */
    public function __construct(string $key, string $carrier, array $domains, string $version)
    {
        parent::__construct($key, $carrier, [ProposalKind::Shipped, ProposalKind::Delivered], $domains, $version);
    }

    final public function parse(MailMessage $mail): OrderProposal
    {
        $kind   = $this->kind($mail);
        $number = $kind === ProposalKind::NoReceipt ? null : $this->parcelNumber($mail);

        if ($number === null) {
            return new OrderProposal($this->shopName(), '', ProposalKind::NoReceipt);
        }

        return $this->notice($mail, $kind, $number);
    }

    /** Shipped, Delivered, or NoReceipt for a mail of the carrier that moves no parcel. */
    abstract protected function kind(MailMessage $mail): ProposalKind;

    abstract protected function parcelNumber(MailMessage $mail): ?string;

    /** The proposal for a parcel; a carrier that announces more (a delivery window) adds it here. */
    protected function notice(MailMessage $mail, ProposalKind $kind, string $number): OrderProposal
    {
        return new OrderProposal($this->shopName(), 'sendung:' . $number, $kind, trackingNumbers: [$number], numberMissing: true);
    }
}
