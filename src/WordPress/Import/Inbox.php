<?php declare(strict_types=1);

namespace Taxmod\WordPress\Import;

use Taxmod\Core\Import\EnrichingSource;
use Taxmod\Core\Import\FetchingSource;
use Taxmod\Core\Import\ImportSource;
use Taxmod\Core\Import\Intake;
use Taxmod\Core\Import\OrderProposal;

/**
 * Runs a fetching source and files what it brings: once per external id, only what the duty table lets this source
 * bring (D-953), completed by any enriching source for the platform. Whether a proposal is new or an update is decided
 * on the review page, where the logged-in user may read the model (D-936) — a cron run has no user.
 *
 * ```mermaid
 * flowchart LR
 *   F["source: fetch(cursor)"] --> D{"seen? allowed?"}
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
    private const LAST_RUN_OPTION = 'taxmod_import_last_run';

    public function __construct(private readonly InboxTable $table = new InboxTable())
    {
    }

    /** @return int how many proposals were filed */
    public function run(FetchingSource $source): int
    {
        $sources = Sources::all();
        $intake = new Intake(Duties::load(), array_keys($sources));
        $filed = 0;

        try {
            $batch = $source->fetch(self::cursor($source->key()));

            foreach ($batch->proposals as $incoming) {
                if ($this->table->has($source->key(), (string) $incoming->proposal->externalId) || ! $intake->accepts($source->key(), $incoming->proposal)) {
                    continue;
                }

                $id = $this->table->add($source->key(), $incoming);
                $this->enrich($id, $incoming->proposal, $sources);
                $filed++;
            }

            update_option(self::CURSOR_OPTION . $source->key(), $batch->cursor, false);
            self::remember($source->key(), $filed, null);
        } catch (\Throwable $failure) {
            self::remember($source->key(), $filed, $failure->getMessage());
            throw $failure;
        }

        return $filed;
    }

    /** The scheduled run: every fetching source whose interval has passed. */
    public function runDue(): void
    {
        $last = self::lastRuns();

        foreach (Sources::all() as $key => $source) {
            if (! $source instanceof FetchingSource || $source->interval() <= 0) {
                continue;
            }

            if (time() - (int) ($last[$key]['at'] ?? 0) < $source->interval()) {
                continue;
            }

            try {
                $this->run($source);
            } catch (\Throwable) {
                // Remembered in the last run; the next source still runs.
            }
        }
    }

    /** @return array<string, array{at: int, filed: int, error: ?string}> */
    public static function lastRuns(): array
    {
        $stored = get_option(self::LAST_RUN_OPTION, []);

        return is_array($stored) ? $stored : [];
    }

    /** @param array<string, ImportSource> $sources */
    private function enrich(int $id, OrderProposal $proposal, array $sources): void
    {
        foreach ($sources as $enricher) {
            if (! $enricher instanceof EnrichingSource || $enricher->key() === $proposal->source) {
                continue;
            }

            $platforms = array_map('mb_strtolower', $enricher->enriches());

            if (! in_array(mb_strtolower(trim($proposal->platform)), $platforms, true)) {
                continue;
            }

            $completed = $enricher->enrich($proposal);

            if ($completed !== null) {
                $proposal = $completed;
                $this->table->replaceProposal($id, $proposal);
            }
        }
    }

    private static function cursor(string $key): ?string
    {
        $cursor = get_option(self::CURSOR_OPTION . $key, null);

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    private static function remember(string $key, int $filed, ?string $error): void
    {
        $runs = self::lastRuns();
        $runs[$key] = ['at' => time(), 'filed' => $filed, 'error' => $error];
        update_option(self::LAST_RUN_OPTION, $runs, false);
    }
}
