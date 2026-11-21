<?php declare(strict_types=1);

namespace Taxmod\WordPress\Privacy;

use Taxmod\Core\Privacy\FieldCipher;
use Taxmod\WordPress\Persistence\Query;
use Taxmod\WordPress\Persistence\Schema;

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

        return self::sealRows('relation_records', $kanten, $limit)
            + self::sealRows('relation_records_history', $kanten, $limit)
            + self::sealJournal($kanten, $limit);
    }

    /**
     * What is still plain on the confidential edges — the number the check and the settings read.
     *
     * @return array{live: int, history: int, journal: int}
     */
    public static function plainLeft(): array
    {
        $kanten = array_keys(FieldSeal::confidentialRelations());

        if ($kanten === []) {
            return ['live' => 0, 'history' => 0, 'journal' => 0];
        }

        global $wpdb;

        [$wo, $params] = self::plainRowsWhere($kanten);

        return [
            'live'    => (int) Query::value('Klartext an vertraulichen Kanten zählen', $wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('relation_records') . $wo, ...$params)),
            'history' => (int) Query::value('Klartext im Schatten vertraulicher Kanten zählen', $wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table('relation_records_history') . $wo, ...$params)),
            'journal' => count(self::plainJournalRows($kanten, PHP_INT_MAX)),
        ];
    }

    /** One live value sealed with this key id, to test an entered key against; null while nothing is sealed with it. */
    public static function sealedSample(string $keyId): ?string
    {
        global $wpdb;

        $value = Query::value('Einen versiegelten Wert lesen', $wpdb->prepare(
            'SELECT value_text FROM ' . Schema::table('relation_records') . ' WHERE value_text LIKE %s LIMIT 1',
            $wpdb->esc_like(FieldCipher::PREFIX . $keyId . ':') . '%'
        ));

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param list<int> $kanten */
    private static function sealRows(string $tabelle, array $kanten, int $limit): int
    {
        global $wpdb;

        [$wo, $params] = self::plainRowsWhere($kanten);
        $schluessel    = $tabelle === 'relation_records' ? 'id' : 'id, version';
        $zeilen        = Query::rows('Klartext an vertraulichen Kanten lesen', $wpdb->prepare(
            'SELECT ' . $schluessel . ', relation_id, value_text FROM ' . Schema::table($tabelle) . $wo . ' LIMIT %d',
            ...[...$params, $limit]
        ));

        foreach ($zeilen ?: [] as $zeile) {
            $wo = ['id' => (int) $zeile['id']];

            if (isset($zeile['version'])) {
                $wo['version'] = (int) $zeile['version'];
            }

            $wpdb->update(
                Schema::table($tabelle),
                ['value_text' => FieldSeal::sealText((int) $zeile['relation_id'], (string) $zeile['value_text'])],
                $wo,
                ['%s'],
                array_fill(0, count($wo), '%d')
            );
        }

        return count($zeilen ?: []);
    }

    /**
     * @param list<int> $kanten
     * @return array{0: string, 1: list<int|string>}
     */
    private static function plainRowsWhere(array $kanten): array
    {
        global $wpdb;

        return [
            ' WHERE relation_id IN (' . implode(',', array_fill(0, count($kanten), '%d')) . ") AND value_text IS NOT NULL AND value_text <> '' AND value_text NOT LIKE %s",
            [...$kanten, $wpdb->esc_like(FieldCipher::PREFIX) . '%'],
        ];
    }

    /** @param list<int> $kanten */
    private static function sealJournal(array $kanten, int $limit): int
    {
        global $wpdb;

        $zeilen = self::plainJournalRows($kanten, $limit);

        foreach ($zeilen as $zeile) {
            $wpdb->update(
                Schema::table('changelog'),
                ['before_state' => FieldSeal::sealState($zeile['before_state']), 'after_state' => FieldSeal::sealState($zeile['after_state'])],
                ['id' => (int) $zeile['id']],
                ['%s', '%s'],
                ['%d']
            );
        }

        return count($zeilen);
    }

    /**
     * The chronicle rows that freeze a plain, non-empty value of a confidential edge (`relation=<id> locale=…`). The SQL
     * narrows by the edge, PHP decides by the parsed state.
     *
     * @param list<int> $kanten
     * @return list<array{id: int|string, before_state: ?string, after_state: ?string}>
     */
    private static function plainJournalRows(array $kanten, int $limit): array
    {
        global $wpdb;

        $muster = [];
        $params = [];

        foreach ($kanten as $kante) {
            foreach (['relation', 'path'] as $schluessel) {
                $muster[] = '(before_state LIKE %s OR after_state LIKE %s)';
                $like     = '%' . $wpdb->esc_like(' ' . $schluessel . '=' . $kante . ' ') . '%';
                array_push($params, $like, $like);
            }
        }

        $aus = [];

        Query::each('Chronik vertraulicher Kanten lesen', $wpdb->prepare(
            'SELECT id, before_state, after_state FROM ' . Schema::table('changelog') . ' WHERE ' . implode(' OR ', $muster) . ' ORDER BY id ASC',
            ...$params
        ), static function (array $zeile) use (&$aus, $limit): void {
            if (count($aus) >= $limit) {
                return;
            }

            if (FieldSeal::stateIsPlain($zeile['before_state']) || FieldSeal::stateIsPlain($zeile['after_state'])) {
                $aus[] = $zeile;
            }
        });

        return $aus;
    }
}
