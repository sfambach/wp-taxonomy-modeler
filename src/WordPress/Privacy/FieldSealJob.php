<?php declare(strict_types=1);

namespace Taxmod\WordPress\Privacy;

use Taxmod\WordPress\Persistence\FieldSealRows;

/**
 * Seals what a field held before it became confidential (D-1514): its live rows, its history rows and its values in the
 * chronicle — in batches, so a large table never holds one request long.
 *
 * ```mermaid
 * flowchart LR
 *   T["admin_init, höchstens alle 5 Min"] --> J["runBatch()"]
 *   J --> L[("relation_records")] & H[("relation_records_history")] & C[("changelog")]
 *   J -->|Arbeit übrig| T
 * ```
 *
 * ⚠️ *INFERRED: sealing changes the stored form, not the value, so it is no act, raises no version and writes no shadow
 * row — an undo must never bring a plain text back. The rows stay where they are, with the same id.*
 *
 * @see docs/pakete/vertraulich/konzept.md
 */
final class FieldSealJob
{
    public const THROTTLE = 'taxmod_field_seal_next';

    private const BATCH = 500;

    /** Hooked on admin_init: a short look at most every five minutes, at once again while work is left. */
    public static function onAdmin(): void
    {
        if (get_transient(self::THROTTLE) !== false || FieldSeal::confidentialRelations() === [] || FieldSeal::cipher() === null) {
            return;
        }

        $done = self::runBatch();
        set_transient(self::THROTTLE, 1, $done >= self::BATCH ? 1 : 5 * MINUTE_IN_SECONDS);
    }

    /** One batch over the three tables; answers how many rows it sealed. */
    public static function runBatch(int $limit = self::BATCH): int
    {
        $kanten = array_keys(FieldSeal::confidentialRelations());

        if ($kanten === [] || FieldSeal::cipher() === null) {
            return 0;
        }

        $done = 0;

        foreach (['relation_records', 'relation_records_history'] as $tabelle) {
            foreach (FieldSealRows::plainRows($tabelle, $kanten, $limit) as $zeile) {
                FieldSealRows::replaceText($tabelle, $zeile['id'], $zeile['version'], (string) FieldSeal::sealText($zeile['relation_id'], $zeile['text']));
                $done++;
            }
        }

        foreach (self::plainJournalRows($kanten, $limit) as $zeile) {
            FieldSealRows::replaceJournalStates($zeile['id'], FieldSeal::sealState($zeile['before_state']), FieldSeal::sealState($zeile['after_state']));
            $done++;
        }

        return $done;
    }

    /**
     * What is still plain on the confidential edges — the number the check reads.
     *
     * @return array{live: int, history: int, journal: int}
     */
    public static function plainLeft(): array
    {
        $kanten = array_keys(FieldSeal::confidentialRelations());

        if ($kanten === []) {
            return ['live' => 0, 'history' => 0, 'journal' => 0];
        }

        return [
            'live'    => FieldSealRows::plainCount('relation_records', $kanten),
            'history' => FieldSealRows::plainCount('relation_records_history', $kanten),
            'journal' => count(self::plainJournalRows($kanten, PHP_INT_MAX)),
        ];
    }

    /**
     * @param list<int> $kanten
     * @return list<array{id: int, before_state: ?string, after_state: ?string}>
     */
    private static function plainJournalRows(array $kanten, int $limit): array
    {
        return FieldSealRows::journalRowsOn(
            $kanten,
            static fn (?string $before, ?string $after): bool => FieldSeal::stateIsPlain($before) || FieldSeal::stateIsPlain($after),
            $limit
        );
    }
}
