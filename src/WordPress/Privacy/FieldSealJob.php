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

        if ($done === 0) {
            FieldSeal::noteSealedWith(self::sealedWithRetired(), time());
        }

        set_transient(self::THROTTLE, 1, $done >= self::BATCH ? 1 : 5 * MINUTE_IN_SECONDS);
    }

    /** One batch over the three tables — values of a former key resealed (D-1562), plain values sealed; answers how many rows. */
    public static function runBatch(int $limit = self::BATCH): int
    {
        FieldSeal::ready();
        $cipher = FieldSeal::cipher();

        if ($cipher === null) {
            return 0;
        }

        $done   = self::resealBatch($cipher, $limit);
        $kanten = array_keys(FieldSeal::confidentialRelations());

        if ($kanten === []) {
            return $done;
        }

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

    /** D-1562: values sealed with a former key, sealed again with the current one — no act, as sealing is none. */
    private static function resealBatch(\Taxmod\Core\Privacy\FieldCipher $cipher, int $limit): int
    {
        $done  = 0;
        $keyId = $cipher->sealingKeyId();

        foreach (['relation_records', 'relation_records_history'] as $tabelle) {
            foreach (FieldSealRows::sealedOtherRows($tabelle, $keyId, $limit) as $zeile) {
                try {
                    FieldSealRows::replaceText($tabelle, $zeile['id'], $zeile['version'], $cipher->reseal($zeile['text']));
                    $done++;
                } catch (\Taxmod\Core\Exception\FieldDoesNotOpen) {
                    // *A value no key here opens (a key deleted, a foreign copy) stays as it is and reads as the lock.*
                }
            }
        }

        $journal = FieldSealRows::journalSealedRows(
            static fn (?string $before, ?string $after): bool => FieldSeal::stateNeedsResealing($before) || FieldSeal::stateNeedsResealing($after),
            $limit
        );

        foreach ($journal as $zeile) {
            try {
                FieldSealRows::replaceJournalStates($zeile['id'], FieldSeal::resealState($zeile['before_state']), FieldSeal::resealState($zeile['after_state']));
                $done++;
            } catch (\Taxmod\Core\Exception\FieldDoesNotOpen) {
                // *As above: what does not open is left.*
            }
        }

        return $done;
    }

    /**
     * D-1562: what is still sealed with a former key — live, history, chronicle.
     *
     * @return array{live: int, history: int, journal: int}
     */
    public static function resealLeft(): array
    {
        $cipher = FieldSeal::cipher();

        if ($cipher === null) {
            return ['live' => 0, 'history' => 0, 'journal' => 0];
        }

        $keyId = $cipher->sealingKeyId();

        return [
            'live'    => FieldSealRows::sealedCount('relation_records', $keyId),
            'history' => FieldSealRows::sealedCount('relation_records_history', $keyId),
            'journal' => count(FieldSealRows::journalSealedRows(
                static fn (?string $before, ?string $after): bool => FieldSeal::stateNeedsResealing($before) || FieldSeal::stateNeedsResealing($after),
                PHP_INT_MAX
            )),
        ];
    }

    /**
     * Per former key of the tab: how many values are still sealed with it (live, history, chronicle together).
     *
     * @return array<string, int>
     */
    public static function sealedWithRetired(): array
    {
        $aus = [];

        foreach (array_keys(FieldSeal::retired()) as $keyId) {
            $aus[$keyId] = FieldSealRows::sealedCount('relation_records', $keyId, true)
                + FieldSealRows::sealedCount('relation_records_history', $keyId, true)
                + FieldSealRows::journalSealedWithCount($keyId);
        }

        return $aus;
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
