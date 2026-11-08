<?php declare(strict_types=1);

namespace Taxmod\WordPress\Printing;

use Taxmod\Core\Printing\LabelContent;
use Taxmod\Core\Printing\LabelPrefix;
use Taxmod\Core\Printing\PrintJobStatus;
use Taxmod\WordPress\ModelAbilities;
use Taxmod\WordPress\Plugin;

/**
 * The print jobs as abilities, so the print thread prints a label, reads what CUPS did and prints a job again without opening wp-admin (D-1235).
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

        wp_register_ability('taxmod/print-label', [
            'label'               => __('Print a label', 'taxmod'),
            'description'         => __('Queues one label for the print service, built as the label dialog builds it: code (M-<material>, P-<lot>, O-/K-<place>), label (record id of a label template), printer (record id; empty: the first whose roll allows the label), copies. dry_run: true answers what would print and queues nothing. pdf: true adds the label as base64 PDF, drawn as it lands on paper — to look at instead of a test print (D-1239). Returns the job id; follow it with taxmod/print-jobs.', 'taxmod'),
            'category'            => ModelAbilities::CATEGORY,
            'input_schema'        => [
                'type'       => 'object',
                'properties' => [
                    'code'    => ['type' => 'string', 'pattern' => '^[A-Za-z]-[0-9]+$'],
                    'label'   => ['type' => 'integer', 'minimum' => 1],
                    'printer' => ['type' => 'integer', 'minimum' => 1],
                    'copies'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => LabelButton::MAX_COPIES, 'default' => 1],
                    'dry_run' => ['type' => 'boolean', 'default' => false],
                    'pdf'     => ['type' => 'boolean', 'default' => false],
                ],
                'required'   => ['code', 'label'],
                'additionalProperties' => false,
            ],
            'execute_callback'    => fn (mixed $input = null): array|\WP_Error => $this->printLabel(is_array($input) ? $input : []),
            'permission_callback' => self::allowed(...),
            'meta'                => ['public' => true, 'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false]],
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

    /** @param array<string, mixed> $input */
    private function printLabel(array $input): array|\WP_Error
    {
        $code = strtoupper(trim((string) ($input['code'] ?? '')));
        if (! LabelPrefix::isCode($code)) {
            return new \WP_Error('taxmod_print_code', __('A code like M-123 or P-123 is needed.', 'taxmod'), ['status' => 400]);
        }
        [$prefix, $id] = explode('-', $code, 2);
        $template = absint($input['label'] ?? 0);
        $printer  = absint($input['printer'] ?? 0);
        $copies   = LabelButton::copies((int) ($input['copies'] ?? 1));
        // D-1235: the module that owns the code builds the label as its dialog does (the stock: materials and lots).
        $found = apply_filters('taxmod_label_for', null, $prefix, (int) $id, $template, $printer);
        if (! is_array($found) || ! is_array($found['template'] ?? null)) {
            return new \WP_Error('taxmod_print_label', __('No such label for this code, or the printer has no roll for it.', 'taxmod'), ['status' => 400]);
        }
        $label  = new LabelContent($code, (string) ($found['title'] ?? $code), (string) ($found['subtitle'] ?? ''));
        $filled = LabelButton::filled($label, $found['template']);
        if ($filled === null) {
            return new \WP_Error('taxmod_print_label', __('The label could not be filled.', 'taxmod'), ['status' => 400]);
        }
        $answer = ['code' => $code, 'title' => $label->title, 'label' => $filled->templateName, 'queue' => $filled->printer?->queue ?? '', 'copies' => $copies, 'values' => $filled->values];
        if (! empty($input['pdf'])) {
            // D-1239: one copy, as the dialog's preview draws it (D-1228) — what to look at before paper is spent.
            $answer['pdf'] = base64_encode($filled->render(1, true));
        }
        if (! empty($input['dry_run'])) {
            return $answer + ['job' => 0];
        }
        if (! PrintService::isSetUp()) {
            return new \WP_Error('taxmod_print_service', __('The print service is not set up.', 'taxmod'), ['status' => 409]);
        }

        return $answer + ['job' => (new PrintJobs())->queue($filled->format, $label, false, $filled->toJson(), $copies)];
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
