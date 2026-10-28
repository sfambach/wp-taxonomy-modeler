<?php declare(strict_types=1);

namespace Taxmod\Core\Import\Mail;

use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;

/**
 * One mail connection: recognises the mails of one shop and reads an order proposal from them. It lives in the shop's
 * own module and registers through the filter `taxmail_parsers`; the Mail module only fetches and files (D-1006).
 *
 * @see docs/pakete/import/module-schnitt.md
 */
interface OrderMailParser
{
    /** Stable key for the settings, e.g. «reichelt». */
    public function key(): string;

    /** The shop's name as shown in the settings — a proper name, not translated. */
    public function shopName(): string;

    public function recognises(MailMessage $mail): bool;

    /** Only called after recognises() said yes; throws ParserException when the mail is the shop's but unreadable. */
    public function parse(MailMessage $mail): OrderProposal;

    /**
     * Which mails it reads, for the part «Mail» of its module's tab (D-1007).
     *
     * @return non-empty-list<ProposalKind>
     */
    public function kinds(): array;

    /** The version of the module that brings it: a new one makes the Mail module read «Nicht erkannt» again. */
    public function version(): string;
}
