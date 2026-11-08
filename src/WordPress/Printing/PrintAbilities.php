<?php declare(strict_types=1);

namespace Taxmod\WordPress\Printing;

use Taxmod\Core\Printing\PrintJobStatus;
use Taxmod\WordPress\ModelAbilities;
use Taxmod\WordPress\Plugin;

/**
 * The print jobs as abilities, so the print thread reads what CUPS did and prints a job again without opening wp-admin (D-1235).
 *
 * @see docs/NewConcept/90-decision-log.md D-1234, D-1235
 */
final class PrintAbilities
{
    private const MAX_JOBS = 50;

    public function register(): void
    {
        $statuses = array_map(static fn (PrintJobStatus $s): string => $s->value, PrintJobStatus::cases());

        wp_register_ability('taxmod/print-jobs', [
            'label'               => __('Read print jobs', 'taxmod'),
            'description'         => __('The newest label print jobs, newest first: id, status (queued, claimed, sent = at the printer, printed, failed, cancelled), code, title, label, printer queue, copies, what CUPS said, times (UTC). Also when the print service was last seen and which printers it serves.', 'taxmod'),
            'category'            => ModelAbilities::CATEGORY,
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'limit'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_JOBS, 'default' => 10],
                    'status' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $statuses]],
                ],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null): array => $this->jobs(is_array($input) ? $input : []),
            'permission_callback' => self::allowed(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true]],
        ]);

        wp_register_ability('taxmod/print-job', [
            'label'               => __('Print a job again or cancel it', 'taxmod'),
            'description'         => __('again: a printed, failed or cancelled job goes back into the queue and prints as before; cancel: a job still waiting is not printed. Returns how many jobs moved.', 'taxmod'),
            'category'            => ModelAbilities::CATEGORY,
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'ids'    => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'minItems' => 1, 'maxItems' => self::MAX_JOBS],
                    'action' => ['type' => 'string', 'enum' => ['again', 'cancel']],
                ],
                'required'   => ['ids', 'action'],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null): array => $this->act(is_array($input) ? $input : []),
            'permission_callback' => self::allowed(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
        ]);
    }

    public static function allowed(mixed $input = null): bool
    {
        return current_user_can(Plugin::CAPABILITY);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{service: array{agent: string, last_seen: string, printers: list<string>}, jobs: list<array<string, mixed>>}
     */
    private function jobs(array $input): array
    {
        $limit    = max(1, min(self::MAX_JOBS, (int) ($input['limit'] ?? 10)));
        $statuses = array_values(array_filter(array_map('strval', (array) ($input['status'] ?? [])), static fn (string $s): bool => PrintJobStatus::tryFrom($s) !== null));
        $service  = PrintService::settings();
        $jobs     = [];

        foreach ((new PrintJobs())->latest($limit, ...$statuses) as $row) {
            $document = json_decode((string) ($row['document'] ?? ''), true);
            $document = is_array($document) ? $document : [];
            $jobs[]   = [
                'id'          => (int) $row['id'],
                'status'      => (string) $row['status'],
                'code'        => (string) $row['code'],
                'title'       => (string) $row['title'],
                'label'       => (string) ($document['template'] ?? $row['format']),
                'queue'       => (string) ($document['printer']['queue'] ?? ''),
                'copies'      => (int) $row['copies'],
                'message'     => (string) ($row['message'] ?? ''),
                'created_at'  => (string) $row['created_at'],
                'claimed_at'  => (string) ($row['claimed_at'] ?? ''),
                'finished_at' => (string) ($row['finished_at'] ?? ''),
            ];
        }

        return ['service' => ['agent' => $service['agent'], 'last_seen' => $service['last_seen'], 'printers' => $service['printers']], 'jobs' => $jobs];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{action: string, moved: int}
     */
    private function act(array $input): array
    {
        $ids    = array_values(array_unique(array_filter(array_map('intval', (array) ($input['ids'] ?? [])), static fn (int $id): bool => $id > 0)));
        $action = (string) ($input['action'] ?? '');
        $jobs   = new PrintJobs();
        $moved  = $ids === [] ? 0 : match ($action) {
            'again'  => $jobs->queueAgain($ids),
            'cancel' => $jobs->cancel($ids),
            default  => 0,
        };
        return ['action' => $action, 'moved' => $moved];
    }
}
