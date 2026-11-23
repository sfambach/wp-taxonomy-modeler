<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Import\BacklogSource;
use Taxmod\Core\Import\DutyRole;
use Taxmod\Core\Import\EnrichingSource;
use Taxmod\Core\Import\ExistingOrder;
use Taxmod\Core\Import\FetchBatch;
use Taxmod\Core\Import\FetchingSource;
use Taxmod\Core\Import\ImportSource;
use Taxmod\Core\Import\IncomingProposal;
use Taxmod\Core\Import\Intake;
use Taxmod\Core\Import\IntakeVerdict;
use Taxmod\Core\Import\ModelLookup;
use Taxmod\Core\Import\OrderComparison;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\RestartingSource;
use Taxmod\Core\Import\SearchingSource;
use Taxmod\WordPress\Plugin;

/**
 * Runs a source and files what it brings: once per external id and once per message (a forwarded copy), only what the
 * duty table lets this source bring (D-953), completed by any enriching source for the platform. Whether a proposal is
 * new or an update is decided on the review page, where the logged-in user may read the model (D-936).
 *
 * ```mermaid
 * flowchart LR
 *   F["fetch / backlog / search"] --> D{"seen? allowed?"}
 *   D -->|new| A["inbox row"]
 *   A --> E["enriching sources"]
 *   F --> C["store cursor"]
 * ```
 *
 * @see docs/pakete/import/konzept.md §4
 */
final class Inbox
{
    public const SCHEDULE_HOOK = 'taxmod_import_fetch';
    private const CURSOR_OPTION = 'taxmod_import_cursor_';
    private const BACKLOG_OPTION = 'taxmod_import_backlog_';
    private const BACKLOG_ERROR_OPTION = 'taxmod_import_backlog_error_';
    private const LAST_RUN_OPTION = 'taxmod_import_last_run';
    /** What the hourly run gives the backlog; the source cuts its own slices shorter. */
    public const BACKLOG_STRETCH_SECONDS = 15;

    private ?ModelGateway $model = null;

    public function __construct(private readonly InboxTable $table = new InboxTable())
    {
    }

    /** @return int how many proposals were filed */
    public function run(FetchingSource $source): int
    {
        try {
            $batch = $source->fetch(self::stored(self::CURSOR_OPTION, $source->key()));
            $filed = $this->file($source, $batch);
            update_option(self::CURSOR_OPTION . $source->key(), $batch->cursor, false);
            self::remember($source->key(), $filed, null);
        } catch (\Throwable $failure) {
            self::remember($source->key(), 0, $failure->getMessage());
            throw $failure;
        }

        return $filed;
    }

    /**
     * Slices of the backlog until the time budget is spent or the backlog is done.
     *
     * @return array{filed: int, done: bool}
     */
    public function runBacklog(BacklogSource $source, ModelLookup $model, int $budgetSeconds): array
    {
        $started = time();
        $filed = 0;
        $cursor = self::stored(self::BACKLOG_OPTION, $source->key());

        do {
            $batch = $source->backlog($cursor, $model);
            $filed += $this->file($source, $batch);
            $cursor = $batch->cursor;
            update_option(self::BACKLOG_OPTION . $source->key(), $cursor, false);
        } while ($cursor !== null && time() - $started < $budgetSeconds);

        delete_option(self::BACKLOG_ERROR_OPTION . $source->key());

        return ['filed' => $filed, 'done' => $cursor === null];
    }

    /**
     * One stretch of every source's backlog with the hourly run, so the mailbox empties without the button (D-1421). It
     * reads the model, so it acts as the user the automatic take acts as; without one it waits for the button. The
     * cursor is the button's: both go on where the other stopped.
     *
     * @return int how many proposals were filed
     */
    public function sortBacklogs(int $budgetSeconds): int
    {
        $user = AutoTake::user();

        if ($user === 0) {
            return 0;
        }

        $before = get_current_user_id();

        if ($before === 0) {
            wp_set_current_user($user);
        }

        try {
            if (! current_user_can(\Taxmod\WordPress\Rights::PURCHASE) || ! ModelGateway::available()) {
                return 0;
            }

            $filed = 0;

            foreach (Sources::all() as $source) {
                if (! $source instanceof BacklogSource) {
                    continue;
                }

                try {
                    $filed += $this->runBacklog($source, new GatewayLookup(new ModelGateway(Targets::load())), $budgetSeconds)['filed'];
                } catch (\Throwable $failure) {
                    // Kept beside the button, not in the last run: that one's time decides when the next fetch is due.
                    update_option(self::BACKLOG_ERROR_OPTION . $source->key(), wp_date('Y-m-d H:i') . ' — ' . $failure->getMessage(), false);
                }
            }

            return $filed;
        } finally {
            if ($before === 0) {
                wp_set_current_user(0);
            }
        }
    }

    /** The failure of the last hourly backlog stretch, with its time; null after a stretch that went through. */
    public static function backlogError(string $key): ?string
    {
        $error = get_option(self::BACKLOG_ERROR_OPTION . $key, '');

        return is_string($error) && $error !== '' ? $error : null;
    }

    public function search(SearchingSource $source, string $text): int
    {
        return $this->file($source, $source->search($text));
    }

    /** Forgets the source's open proposals, its cursors, then lets it undo its own side. */
    public function restart(ImportSource $source): int
    {
        $forgotten = $this->table->forgetOpen($source->key());
        delete_option(self::CURSOR_OPTION . $source->key());
        delete_option(self::BACKLOG_OPTION . $source->key());
        delete_option(self::BACKLOG_ERROR_OPTION . $source->key());

        if ($source instanceof RestartingSource) {
            $source->restart();
        }

        return $forgotten;
    }

    /** The scheduled run: every fetching source whose interval has passed. */
    public function runDue(): void
    {
        $this->runAll(true);
    }

    /**
     * Every fetching source once, those responsible for new orders first, so a status from another source finds its
     * order (his word, 2026-10-21: «api sollte vor email laufen und email sorgt für updates»). Without `$dueOnly` it is
     * the «Fetch all» button (his word: «ein button fetch all wäre auch nett»).
     *
     * @return int how many proposals were filed
     */
    public function runAll(bool $dueOnly = false): int
    {
        $last = self::lastRuns();
        $ordering = array_values(array_filter(array_map(
            static fn (array $roles): ?string => $roles[DutyRole::Orders->value] ?? null,
            Duties::load()->toArray()
        )));
        $sources = Sources::all();
        uksort($sources, static fn (string $a, string $b): int => (int) ! in_array($a, $ordering, true) <=> (int) ! in_array($b, $ordering, true));
        $filed = 0;

        foreach ($sources as $key => $source) {
            if (! $source instanceof FetchingSource) {
                continue;
            }

            $interval = FetchInterval::of($source);

            if ($dueOnly && ($interval <= 0 || time() - (int) ($last[$key]['at'] ?? 0) < $interval)) {
                continue;
            }

            try {
                $filed += $this->run($source);
            } catch (\Throwable) {
                // Remembered in the last run; the next source still runs.
            }
        }

        return $filed;
    }

    /** @return array<string, array{at: int, filed: int, error: ?string}> */
    public static function lastRuns(): array
    {
        $stored = get_option(self::LAST_RUN_OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    private function file(ImportSource $source, FetchBatch $batch): int
    {
        $sources = Sources::all();
        $intake = new Intake(Duties::load(), array_keys($sources));
        $filed = 0;

        foreach ($batch->proposals as $incoming) {
            $proposal = $incoming->proposal;
            $receivedAt = self::moment($incoming->evidence['received_at'] ?? null);

            if ($this->table->has($source->key(), (string) $proposal->externalId)) {
                $filed += (int) $this->reopenedForModel($source, $incoming, $intake);

                continue;
            }

            if (($receivedAt !== null && $this->table->hasSame($proposal, $receivedAt))
                || ! $intake->accepts($source->key(), $proposal)) {
                continue;
            }

            $id = $this->table->add($source->key(), $incoming, $receivedAt);
            $this->enrich($id, $proposal, $sources);
            $filed++;
        }

        return $filed;
    }

    /**
     * A proposal handled before opens again when the model has empty fields or item prices the source now fills (D-986):
     * the order taken from a mail had no prices, and the API that has them was seen once and never again. Only where
     * the logged-in user may read the model — a scheduled run leaves it to «Fetch all».
     */
    private function reopenedForModel(ImportSource $source, IncomingProposal $incoming, Intake $intake): bool
    {
        $proposal = $incoming->proposal;
        $id = $this->table->closedId($source->key(), (string) $proposal->externalId);

        if ($id === null || $proposal->numberMissing || ! ModelGateway::available()) {
            return false;
        }

        $this->model ??= new ModelGateway(Targets::load());
        $existing = $this->model->side($proposal)?->findOrder($proposal->platform, $proposal->orderNumber);

        if (! $existing instanceof ExistingOrder
            || $intake->decide($source->key(), $proposal, $existing) !== IntakeVerdict::Update
            || (OrderComparison::fills($proposal, $existing) === [] && OrderComparison::lineFills($proposal, $existing) === [])) {
            return false;
        }

        $this->table->reopen($id, $incoming);

        return true;
    }

    /** @param array<string, ImportSource> $sources */
    private function enrich(int $id, OrderProposal $proposal, array $sources): void
    {
        foreach ($sources as $enricher) {
            if (! $enricher instanceof EnrichingSource || $enricher->key() === $proposal->source) {
                continue;
            }

            if (! in_array(mb_strtolower(trim($proposal->platform)), array_map('mb_strtolower', $enricher->enriches()), true)) {
                continue;
            }

            $completed = $enricher->enrich($proposal);

            if ($completed !== null) {
                $proposal = $completed;
                $this->table->replaceProposal($id, $proposal);
            }
        }
    }

    private static function moment(mixed $value): ?string
    {
        // `mixed` because evidence is whatever scalar the source attached.
        $time = is_string($value) ? strtotime($value) : false;

        return $time === false ? null : gmdate('Y-m-d H:i:s', $time);
    }

    private static function stored(string $option, string $key): ?string
    {
        $cursor = get_option($option . $key, null);

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    private static function remember(string $key, int $filed, ?string $error): void
    {
        $runs = self::lastRuns();
        $runs[$key] = ['at' => time(), 'filed' => $filed, 'error' => $error];
        update_option(self::LAST_RUN_OPTION, $runs, false);
    }
}
