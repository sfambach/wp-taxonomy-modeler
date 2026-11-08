<?php declare(strict_types=1);

namespace Taxmod\WordPress\Printing;

use Taxmod\Core\Printing\LabelContent;
use Taxmod\Core\Printing\LabelDocument;
use Taxmod\Core\Printing\LabelFormat;

/**
 * The two calls of the print service (D-1032): «pull» claims every waiting job and hands it over with its PDF, and
 * tells WordPress the service is alive; «result» answers one job. The service proves itself with its token in the
 * header `X-Taxmod-Print-Token`; no WordPress login is involved.
 *
 * @see docs/NewConcept/90-decision-log.md D-1113
 */
final class PrintRest
{
    public const ROUTES = 'taxmod/v1';

    public const TOKEN_HEADER = 'X-Taxmod-Print-Token';

    public static function boot(): void
    {
        add_action('rest_api_init', static fn () => (new self(new PrintJobs()))->register());
    }

    public function __construct(private readonly PrintJobs $jobs)
    {
    }

    public function register(): void
    {
        PrintJobSchema::upgrade();
        register_rest_route(self::ROUTES, '/print/pull', [
            'methods'             => 'POST',
            'callback'            => $this->pull(...),
            'permission_callback' => self::authorised(...),
        ]);
        register_rest_route(self::ROUTES, '/print/jobs/(?P<id>\d+)/result', [
            'methods'             => 'POST',
            'callback'            => $this->result(...),
            'permission_callback' => self::authorised(...),
            'args'                => ['id' => ['validate_callback' => static fn ($v): bool => is_numeric($v) && (int) $v > 0]],
        ]);
        // D-1234: between «CUPS took it» and the end, the service says what CUPS says.
        register_rest_route(self::ROUTES, '/print/jobs/(?P<id>\d+)/progress', [
            'methods'             => 'POST',
            'callback'            => $this->progress(...),
            'permission_callback' => self::authorised(...),
            'args'                => ['id' => ['validate_callback' => static fn ($v): bool => is_numeric($v) && (int) $v > 0]],
        ]);
    }

    public static function authorised(\WP_REST_Request $request): bool|\WP_Error
    {
        $token = (string) $request->get_header(self::TOKEN_HEADER);

        return PrintService::accepts($token) ? true : new \WP_Error('taxmod_print_token', __('Unknown print service.', 'taxmod'), ['status' => 401]);
    }

    public function pull(\WP_REST_Request $request): \WP_REST_Response
    {
        $printers = array_map(
            static fn ($p): string => sanitize_text_field((string) $p),
            array_values(array_filter((array) $request->get_param('printers'), 'is_scalar'))
        );
        PrintService::seen(sanitize_text_field((string) $request->get_param('agent')), $printers);

        $formats = LabelFormat::shipped();
        $jobs = [];
        foreach ($this->jobs->claimWaiting() as $row) {
            // D-1162: the copies as pages of one PDF — one job at the printer, the labels come out together.
            $copies  = max(1, (int) ($row['copies'] ?? 1));
            $printer = null;
            // D-1153: a job from a template carries its own size and layout.
            if (($row['document'] ?? '') !== '' && $row['document'] !== null) {
                try {
                    $filled = \Taxmod\Core\Printing\FilledLabel::fromJson((string) $row['document']);
                } catch (\InvalidArgumentException $e) {
                    $this->jobs->finish((int) $row['id'], false, 'Broken label: ' . $e->getMessage());
                    continue;
                }
                $format  = $filled->format;
                $pdf     = $filled->render($copies);
                $printer = $filled->printer;
            } else {
                $format = $formats[$row['format']] ?? null;
                if ($format === null) {
                    $this->jobs->finish((int) $row['id'], false, 'Unknown label format ' . $row['format']);
                    continue;
                }
                $pdf = LabelDocument::render($format, array_fill(0, $copies, new LabelContent($row['code'], $row['title'], $row['subtitle'])));
            }
            // D-1191: a label printed on a printer of the model carries its queue and options; the service no longer guesses them.
            $jobs[] = ($printer === null ? [] : ['queue' => $printer->queue, 'options' => $printer->optionsFor($format), 'retry' => $printer->retries]) + [
                'id'     => (int) $row['id'],
                'format' => $format->key,
                'roll'   => $format->rollMm,
                'test'   => $row['is_test'] === '1',
                'code'   => $row['code'],
                'copies' => $copies,
                'pdf'    => base64_encode($pdf),
            ];
        }

        return new \WP_REST_Response(['jobs' => $jobs]);
    }

    public function progress(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $id      = (int) $request->get_param('id');
        $message = sanitize_textarea_field((string) $request->get_param('message'));

        if (! $this->jobs->progress($id, substr($message, 0, 2000))) {
            return new \WP_Error('taxmod_print_job', __('This job is not with the print service.', 'taxmod'), ['status' => 409]);
        }

        return new \WP_REST_Response(['id' => $id, 'status' => 'sent']);
    }

    public function result(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $id = (int) $request->get_param('id');
        $printed = rest_sanitize_boolean($request->get_param('ok'));
        $message = sanitize_textarea_field((string) $request->get_param('message'));

        if (! $this->jobs->finish($id, $printed, substr($message, 0, 2000))) {
            return new \WP_Error('taxmod_print_job', __('This job is not waiting for an answer.', 'taxmod'), ['status' => 409]);
        }

        return new \WP_REST_Response(['id' => $id, 'status' => $printed ? 'printed' : 'failed']);
    }
}
