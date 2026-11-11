<?php declare(strict_types=1);

namespace Taxmod\WordPress\Printing;

use Taxmod\Core\Printing\LabelContent;
use Taxmod\Core\Printing\LabelFormat;
use Taxmod\Core\Printing\PrintJobStatus;
use Taxmod\WordPress\Persistence\TileCounts;

/**
 * The print jobs in their table: queue one, let the service claim the waiting ones in one statement, take its result,
 * cancel and print again. Every write is one statement for all rows it touches (CD-7), and forgets the counts per status
 * before it returns (D-1198).
 *
 * @see docs/NewConcept/90-decision-log.md D-1113
 */
final class PrintJobs
{
    /** Sent with a new job, after it is stored; {@see PrintWake} listens. */
    public const QUEUED_ACTION = 'taxmod_print_job_queued';

    /** D-1153: `$document` is a {@see \Taxmod\Core\Printing\FilledLabel} as JSON; the service then draws it instead of the fixed layout. */
    /** D-1162: `$copies` in one job — the service gets one PDF with that many pages, so they come out together. */
    public function queue(LabelFormat $format, LabelContent $label, bool $test, ?string $document = null, int $copies = 1): int
    {
        global $wpdb;

        $wpdb->insert(PrintJobSchema::table(), [
            'format'     => $format->key,
            'code'       => $label->code,
            'title'      => $label->title,
            'subtitle'   => $label->subtitle,
            'document'   => $document,
            'copies'     => max(1, min(LabelButton::MAX_COPIES, $copies)),
            'is_test'    => $test ? 1 : 0,
            'status'     => PrintJobStatus::Queued->value,
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
        ]);
        $id = (int) $wpdb->insert_id;
        TileCounts::forget(TileCounts::PRINT_JOBS);
        if ($id <= 0) {
            throw new \RuntimeException('The print job could not be stored.');
        }

        do_action(self::QUEUED_ACTION, $id);

        return $id;
    }

    /**
     * D-1294: a job that arrives finished — the module that prints renders the PDF (all its pages) and says how it is delivered.
     *
     * @param array{queue?: string, options?: list<string>, retry?: int, roll?: int|float} $delivery the printer queue and options
     *        (D-1191) and the roll width in mm, handed to the service as they are
     */
    public function queuePdf(string $pdf, string $format, string $code, string $title, int $copies, array $delivery, bool $test = false): int
    {
        global $wpdb;

        if ($pdf === '' || ! str_starts_with($pdf, '%PDF')) {
            throw new \InvalidArgumentException('A print job needs a PDF.');
        }

        $wpdb->insert(PrintJobSchema::table(), [
            'format'     => mb_substr($format, 0, 32),
            'code'       => mb_substr($code, 0, 64),
            'title'      => mb_substr($title, 0, 255),
            'subtitle'   => '',
            'pdf'        => $pdf,
            'delivery'   => (string) wp_json_encode($delivery),
            'copies'     => max(1, $copies),
            'is_test'    => $test ? 1 : 0,
            'status'     => PrintJobStatus::Queued->value,
            'created_by' => get_current_user_id(),
            'created_at' => current_time('mysql', true),
        ]);
        $id = (int) $wpdb->insert_id;
        TileCounts::forget(TileCounts::PRINT_JOBS);
        if ($id <= 0) {
            throw new \RuntimeException('The print job could not be stored.');
        }

        do_action(self::QUEUED_ACTION, $id);

        return $id;
    }

    /**
     * D-1221: the state of one job and what the service said about it, or null when there is no such job.
     *
     * @return array{0: PrintJobStatus, 1: string}|null
     */
    public function statusOf(int $id): ?array
    {
        global $wpdb;

        $table = PrintJobSchema::table();
        $row   = $wpdb->get_row($wpdb->prepare("SELECT status, message FROM {$table} WHERE id = %d", $id), ARRAY_A);
        $state = is_array($row) ? PrintJobStatus::tryFrom((string) $row['status']) : null;

        return $state === null ? null : [$state, (string) ($row['message'] ?? '')];
    }

    /**
     * Claims every waiting job, and every claim older than the limit, for one pull.
     *
     * @return list<array<string, string>>
     */
    public function claimWaiting(): array
    {
        global $wpdb;

        $claim = bin2hex(random_bytes(8));
        $now = current_time('mysql', true);
        $stale = gmdate('Y-m-d H:i:s', time() - PrintJobStatus::CLAIM_LIMIT_SECONDS);
        $table = PrintJobSchema::table();

        $claimed = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, claim = %s, claimed_at = %s WHERE status = %s OR (status = %s AND claimed_at < %s)",
            PrintJobStatus::Claimed->value,
            $claim,
            $now,
            PrintJobStatus::Queued->value,
            PrintJobStatus::Claimed->value,
            $stale
        ));
        if ($claimed > 0) {
            TileCounts::forget(TileCounts::PRINT_JOBS);
        }

        return array_map(
            static fn (array $row): array => array_map('strval', $row),
            (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE claim = %s AND status = %s ORDER BY id", $claim, PrintJobStatus::Claimed->value), ARRAY_A)
        );
    }

    /**
     * D-1234: CUPS took the job, or says why it still waits; false when the job is not with the service (any more).
     */
    public function progress(int $id, string $message): bool
    {
        global $wpdb;

        $table = PrintJobSchema::table();
        $moved = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, message = %s WHERE id = %d AND status IN (%s, %s)",
            PrintJobStatus::Sent->value,
            $message,
            $id,
            PrintJobStatus::Claimed->value,
            PrintJobStatus::Sent->value
        ));
        if ($moved > 0) {
            TileCounts::forget(TileCounts::PRINT_JOBS);
        }

        // MySQL counts only changed rows: the same message twice changes nothing, and the job is still with the service.
        return $moved === 1 || ($this->statusOf($id)[0] ?? null) === PrintJobStatus::Sent;
    }

    /** The service's answer for a claimed or sent job; false when the job is not with the service (any more). */
    public function finish(int $id, bool $printed, string $message): bool
    {
        global $wpdb;

        $table = PrintJobSchema::table();

        $finished = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, message = %s, finished_at = %s WHERE id = %d AND status IN (%s, %s)",
            ($printed ? PrintJobStatus::Printed : PrintJobStatus::Failed)->value,
            $message,
            current_time('mysql', true),
            $id,
            PrintJobStatus::Claimed->value,
            PrintJobStatus::Sent->value
        ));
        if ($finished > 0) {
            TileCounts::forget(TileCounts::PRINT_JOBS);
        }

        return $finished === 1;
    }

    /**
     * @param list<int> $ids
     * @return int how many were cancelled
     */
    public function cancel(array $ids): int
    {
        return $this->move($ids, array_filter(PrintJobStatus::cases(), static fn (PrintJobStatus $s): bool => $s->canBeCancelled()), PrintJobStatus::Cancelled);
    }

    /**
     * @param list<int> $ids
     * @return int how many went back to the queue
     */
    public function queueAgain(array $ids): int
    {
        $moved = $this->move($ids, array_filter(PrintJobStatus::cases(), static fn (PrintJobStatus $s): bool => $s->canBeQueuedAgain()), PrintJobStatus::Queued);
        if ($moved > 0) {
            do_action(self::QUEUED_ACTION, 0);
        }

        return $moved;
    }

    /**
     * The newest jobs first, optionally only of the statuses given.
     *
     * @return list<array<string, string>>
     */
    public function latest(int $limit, string ...$statuses): array
    {
        global $wpdb;

        $table = PrintJobSchema::table();
        $statuses = array_values(array_filter($statuses, static fn (string $s): bool => $s !== ''));
        $marks = implode(',', array_fill(0, count($statuses), '%s'));
        $sql = $statuses === []
            ? $wpdb->prepare("SELECT id, format, code, title, subtitle, document, copies, is_test, status, claim, message, created_by, created_at, claimed_at, finished_at FROM {$table} ORDER BY id DESC LIMIT %d", $limit)
            : $wpdb->prepare("SELECT id, format, code, title, subtitle, document, copies, is_test, status, claim, message, created_by, created_at, claimed_at, finished_at FROM {$table} WHERE status IN ({$marks}) ORDER BY id DESC LIMIT %d", ...[...$statuses, $limit]);

        return array_map(static fn (array $row): array => array_map('strval', $row), (array) $wpdb->get_results($sql, ARRAY_A));
    }

    /**
     * D-1307: removes the finished jobs older than `$days`, measured at their end or, without one (cancelled), at their creation.
     * Waiting and claimed jobs stay, whatever their age.
     *
     * @return int how many were removed
     */
    public function purge(int $days, ?\DateTimeImmutable $now = null): int
    {
        global $wpdb;

        if ($days <= 0) {
            return 0;
        }
        $limit = ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
        $finished = array_values(array_map(
            static fn (PrintJobStatus $s): string => $s->value,
            array_filter(PrintJobStatus::cases(), static fn (PrintJobStatus $s): bool => $s->isFinished())
        ));
        if ($finished === []) {
            return 0;
        }
        $table = PrintJobSchema::table();
        $marks = implode(',', array_fill(0, count($finished), '%s'));
        $removed = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status IN ({$marks}) AND COALESCE(finished_at, created_at) < %s",
            ...[...$finished, $limit]
        ));
        if ($removed > 0) {
            TileCounts::forget(TileCounts::PRINT_JOBS);
        }

        return $removed;
    }

    /**
     * Kept until the next write (D-1198).
     *
     * @return array<string, int> status => count
     */
    public function counts(): array
    {
        return TileCounts::remember(TileCounts::PRINT_JOBS, 'status', static function (): array {
            global $wpdb;

            $table = PrintJobSchema::table();
            $counts = [];
            foreach ((array) $wpdb->get_results("SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A) as $row) {
                $counts[(string) $row['status']] = (int) $row['n'];
            }

            return $counts;
        });
    }

    /**
     * @param list<int> $ids
     * @param array<PrintJobStatus> $from
     */
    private function move(array $ids, array $from, PrintJobStatus $to): int
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === [] || $from === []) {
            return 0;
        }
        $table = PrintJobSchema::table();
        $idMarks = implode(',', array_fill(0, count($ids), '%d'));
        $statusMarks = implode(',', array_fill(0, count($from), '%s'));
        $values = array_merge(
            [$to->value],
            $ids,
            array_map(static fn (PrintJobStatus $s): string => $s->value, array_values($from))
        );

        $moved = (int) $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, claim = '', message = NULL, claimed_at = NULL, finished_at = NULL WHERE id IN ({$idMarks}) AND status IN ({$statusMarks})",
            ...$values
        ));
        if ($moved > 0) {
            TileCounts::forget(TileCounts::PRINT_JOBS);
        }

        return $moved;
    }
}
