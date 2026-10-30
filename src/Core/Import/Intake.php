<?php declare(strict_types=1);

namespace Taxmod\Core\Import;

/**
 * Decides, for one incoming proposal, whether it becomes something to review — by platform and order number against
 * the model (duplicates, D-953) and by the duty table (S4).
 *
 * ```mermaid
 * flowchart LR
 *   P["proposal"] --> E{"order in model?"}
 *   E -->|yes| S{"new tracking / delivery, or other order fields?"}
 *   S -->|yes, may bring status / is the truth| U["Update"]
 *   S -->|no| N["Nothing"]
 *   E -->|no| O{"items, may bring orders?"}
 *   O -->|yes| W["NewOrder"]
 *   O -->|no| N
 * ```
 *
 * @see docs/pakete/import/konzept.md §3.4
 */
final class Intake
{
    /** Kinds that can bring an order the model does not have yet, as the mail import read them. */
    private const ORDER_KINDS = [ProposalKind::Ordered, ProposalKind::Shipped, ProposalKind::Delivered, ProposalKind::Invoice, ProposalKind::Other, ProposalKind::Sold];

    /** @param list<string> $activeSources */
    public function __construct(private readonly DutyTable $duties, private readonly array $activeSources)
    {
    }

    /** Whether the inbox keeps a proposal of this source at all — it may bring orders or status for the platform. */
    public function accepts(string $source, OrderProposal $proposal): bool
    {
        return $this->duties->allows($source, $proposal->platform, DutyRole::Orders, $this->activeSources)
            || $this->duties->allows($source, $proposal->platform, DutyRole::Status, $this->activeSources);
    }

    public function decide(string $source, OrderProposal $proposal, ?ExistingOrder $existing): IntakeVerdict
    {
        if ($existing !== null) {
            $status = $this->bringsNews($proposal, $existing);
            // ⚠️ *D-964: the order fields come from the source that is responsible for new orders of the platform — the
            // truth (D-950) — and from nobody else.*
            $fields = OrderComparison::between($proposal, $existing) !== [] || OrderComparison::lineFills($proposal, $existing) !== [];

            if (! $status && ! $fields) {
                return IntakeVerdict::Nothing;
            }

            $statusSource = $this->duties->allows($source, $proposal->platform, DutyRole::Status, $this->activeSources);
            $mayStatus = $status && $statusSource;
            $mayFields = $fields && $this->duties->allows($source, $proposal->platform, DutyRole::Orders, $this->activeSources);

            if ($mayStatus || $mayFields) {
                return IntakeVerdict::Update;
            }

            // ⚠️ *Order fields a status source may not write are not news to it — «another module is responsible» would
            // send him looking for one (2026-10-23, eBay 14-15322-23878).*
            return ! $status && $statusSource ? IntakeVerdict::Nothing : IntakeVerdict::NotResponsible;
        }

        // ⚠️ *A parcel mail without an order number only follows an order; it never starts one (D-1017).*
        if ($proposal->lines === [] || ! in_array($proposal->kind, self::ORDER_KINDS, true)
            || ($proposal->numberMissing && in_array($proposal->kind, [ProposalKind::Shipped, ProposalKind::Delivered], true))) {
            return IntakeVerdict::Nothing;
        }

        return $this->duties->allows($source, $proposal->platform, DutyRole::Orders, $this->activeSources)
            ? IntakeVerdict::NewOrder
            : IntakeVerdict::NotResponsible;
    }

    private function bringsNews(OrderProposal $proposal, ExistingOrder $existing): bool
    {
        if (array_diff($proposal->trackingNumbers, $existing->trackingNumbers) !== []) {
            return true;
        }

        // An invoice the order does not have yet is news (D-1024).
        if ($proposal->invoice !== null && ! in_array($proposal->invoice->number, $existing->invoiceNumbers, true)) {
            return true;
        }

        return $proposal->kind === ProposalKind::Delivered && ($existing->receivedAt === null || $existing->receivedAt === '');
    }
}
