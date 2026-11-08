<?php declare(strict_types=1);

namespace Taxmod\Core\Printing;

/**
 * Where a print job stands on its way to the print service and back (D-1032: pull, claim, result), and which step may follow.
 *
 * ```mermaid
 * stateDiagram-v2
 *   [*] --> queued
 *   queued --> claimed: service pulls
 *   claimed --> sent: CUPS took it (D-1234)
 *   sent --> sent: what CUPS says while it waits
 *   sent --> printed: CUPS sent it to the printer
 *   sent --> failed: CUPS gave it up, or failed once with retrying off
 *   claimed --> printed: service reports ok (a service before D-1234)
 *   claimed --> failed: service reports an error
 *   claimed --> queued: claim older than the limit
 *   queued --> cancelled: owner cancels
 *   printed --> queued: owner prints again
 *   failed --> queued: owner prints again
 *   cancelled --> queued: owner prints again
 * ```
 *
 * @see docs/NewConcept/90-decision-log.md D-1113, D-1234
 */
enum PrintJobStatus: string
{
    case Queued = 'queued';
    case Claimed = 'claimed';
    /** D-1234: CUPS has the job; the service watches it to its end. */
    case Sent = 'sent';
    case Printed = 'printed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** A claim the service never answered goes back to the queue after this long. */
    public const CLAIM_LIMIT_SECONDS = 600;

    public function canBeCancelled(): bool
    {
        return $this === self::Queued;
    }

    /** «Print again» undoes a cancel and repeats a print (every step undoable, his rule of 2026-10-28). */
    public function canBeQueuedAgain(): bool
    {
        return in_array($this, [self::Printed, self::Failed, self::Cancelled], true);
    }

    public function acceptsResult(): bool
    {
        return $this === self::Claimed || $this === self::Sent;
    }
}
