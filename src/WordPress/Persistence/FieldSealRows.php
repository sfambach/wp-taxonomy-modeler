<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Privacy\Confidentiality;
use Taxmod\Core\Addon\AddonRegistry;
use Taxmod\Core\Addon\ConfidentialAddon;
use Taxmod\Core\Model\RecordType;
use Taxmod\Core\Privacy\FieldCipher;

/**
 * The queries of the confidential fields (D-1514, D-1578): which edges and nodes say «Vertraulich», and the rows on them in
 * the live table, the shadow table and the chronicle. The data layer's part; sealing itself is `Privacy\FieldSeal`'s.
 *
 * @see docs/pakete/vertraulich/konzept.md
 */
final class FieldSealRows
{
    /**
     * In one statement: the edges with an active «Vertraulich» (D-1514) and what nodes say for an edge (D-1578).
     *
     * @return array{edges: array<int, true>, overrides: array<int, array<int, bool>>}
     */
    public static function confidentialPlan(): array
    {
        global $wpdb;

        $root = (int) get_option(SeededFrameworkNodes::ROOT_OPTION, 0);
        $rows = Query::rows('Vertrauliche Kanten und Knoten lesen', $wpdb->prepare(
            'SELECT 0 AS node_id, v.relation_id, 1 AS said FROM ' . Schema::table('settings_value') . ' v
             JOIN ' . Schema::table('settings_object') . ' o ON o.id = v.wert_settings_object_id
             WHERE v.attribut = %s AND v.relation_id IS NOT NULL AND v.aktiv = 1 AND o.klasse = %s
             UNION ALL
             SELECT s.node_id, s.relation_id, w.value_int AS said FROM ' . Schema::table('node_records') . ' s
             JOIN ' . Schema::table('relation_records') . ' w ON w.node_record_id = s.id
             JOIN ' . Schema::table('relations_named') . ' k ON k.id = w.relation_id
             WHERE s.record_type = %s AND s.relation_id > 0 AND k.from_node_id = %d AND k.name = %s AND k.kind = %s AND w.value_int IS NOT NULL',
            AddonRegistry::ATTRIBUTE,
            ConfidentialAddon::class,
            RecordType::Settings->value,
            $root,
            Confidentiality::SETTING,
            'setting'
        ));

        $edges     = [];
        $overrides = [];

        foreach ($rows ?: [] as $row) {
            if ((int) $row['node_id'] === 0) {
                $edges[(int) $row['relation_id']] = true;
            } else {
                $overrides[(int) $row['relation_id']][(int) $row['node_id']] = (int) $row['said'] !== 0;
            }
        }

        return ['edges' => $edges, 'overrides' => $overrides];
    }

    /**
     * The node of each record, in one statement.
     *
     * @param list<int> $recordIds
     * @return array<int, int> record id → node id
     */
    public static function nodesOfRecords(array $recordIds): array
    {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map(intval(...), $recordIds))));

        if ($ids === []) {
            return [];
        }

        $rows = Query::rows('Knoten der Saetze lesen', $wpdb->prepare(
            'SELECT id, node_id FROM ' . Schema::table('node_records') . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')',
            ...$ids
        ));
        $aus  = [];

        foreach ($rows ?: [] as $row) {
            $aus[(int) $row['id']] = (int) $row['node_id'];
        }

        return $aus;
    }

    /**
     * D-1578: the rows of a value table on these edges with the node of their record — plain and non-empty, or (`$sealed`)
     * sealed; which of them have to change is decided per node, so the rows come without their text, a page after `$afterId`.
     *
     * @param list<int> $kanten
     * @return list<array{id: int, version: ?int, relation_id: int, node_id: int}>
     */
    public static function rowsWithNode(string $tabelle, array $kanten, bool $sealed, int $afterId, int $limit): array
    {
        global $wpdb;

        if ($kanten === []) {
            return [];
        }

        $tabelle    = self::valueTable($tabelle);
        $mitVersion = $tabelle === 'relation_records_history';
        $form       = $sealed ? 'w.value_text LIKE %s' : "w.value_text IS NOT NULL AND w.value_text <> '' AND w.value_text NOT LIKE %s";
        $zeilen     = Query::rows('Werte vertraulicher Kanten mit Knoten lesen', $wpdb->prepare(
            'SELECT w.id, ' . ($mitVersion ? 'w.version' : 'NULL AS version') . ', w.relation_id, s.node_id FROM ' . Schema::table($tabelle) . ' w
             JOIN ' . Schema::table('node_records') . ' s ON s.id = w.node_record_id
             WHERE w.relation_id IN (' . implode(',', array_fill(0, count($kanten), '%d')) . ') AND ' . $form . ' AND w.id > %d
             ORDER BY w.id ASC LIMIT %d',
            ...[...$kanten, $wpdb->esc_like(FieldCipher::PREFIX) . '%', $afterId, $limit]
        ));

        return array_map(static fn (array $z): array => [
            'id'          => (int) $z['id'],
            'version'     => $z['version'] === null ? null : (int) $z['version'],
            'relation_id' => (int) $z['relation_id'],
            'node_id'     => (int) $z['node_id'],
        ], $zeilen ?: []);
    }

    /**
     * The texts of these rows, by id.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public static function textsOf(string $tabelle, array $ids): array
    {
        global $wpdb;

        if ($ids === []) {
            return [];
        }

        $rows = Query::rows('Texte nach Id lesen', $wpdb->prepare(
            'SELECT id, value_text FROM ' . Schema::table(self::valueTable($tabelle)) . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')',
            ...array_map(intval(...), $ids)
        ));
        $aus  = [];

        foreach ($rows ?: [] as $row) {
            $aus[(int) $row['id']] = (string) $row['value_text'];
        }

        return $aus;
    }

    /** The stored form of one row's text, in place: same id, same version, no shadow row. */
    public static function replaceText(string $tabelle, int $id, ?int $version, string $text): void
    {
        global $wpdb;

        $wo = $version === null ? ['id' => $id] : ['id' => $id, 'version' => $version];

        $wpdb->update(Schema::table(self::valueTable($tabelle)), ['value_text' => $text], $wo, ['%s'], array_fill(0, count($wo), '%d'));
    }

    /**
     * The chronicle rows that name one of these edges (`relation=<id> ` or, older, `path=<id> `) and pass `$keep`.
     *
     * @param list<int>                                      $kanten
     * @param \Closure(?string, ?string): bool              $keep before, after
     * @return list<array{id: int, before_state: ?string, after_state: ?string}>
     */
    public static function journalRowsOn(array $kanten, \Closure $keep, int $limit): array
    {
        global $wpdb;

        if ($kanten === []) {
            return [];
        }

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
        ), static function (array $z) use (&$aus, $keep, $limit): void {
            $vorher  = $z['before_state'] === null ? null : (string) $z['before_state'];
            $nachher = $z['after_state'] === null ? null : (string) $z['after_state'];

            if (count($aus) < $limit && $keep($vorher, $nachher)) {
                $aus[] = ['id' => (int) $z['id'], 'before_state' => $vorher, 'after_state' => $nachher];
            }
        });

        return $aus;
    }

    public static function replaceJournalStates(int $id, ?string $before, ?string $after): void
    {
        global $wpdb;

        $wpdb->update(Schema::table('changelog'), ['before_state' => $before, 'after_state' => $after], ['id' => $id], ['%s', '%s'], ['%d']);
    }

    /**
     * D-1562: how many rows of a value table hold a text sealed with another key than `$keyId` — what a change of key
     * leaves to reseal; or, with `$with` true, how many are sealed with `$keyId` itself.
     */
    public static function sealedCount(string $tabelle, string $keyId, bool $with = false): int
    {
        global $wpdb;

        [$wo, $params] = self::sealedWhere($keyId, $with);

        return (int) Query::value('Versiegelte Werte je Schlüssel zählen', $wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table(self::valueTable($tabelle)) . $wo, ...$params));
    }

    /**
     * Up to `$limit` rows sealed with another key than `$keyId`.
     *
     * @return list<array{id: int, version: ?int, text: string}>
     */
    public static function sealedOtherRows(string $tabelle, string $keyId, int $limit): array
    {
        global $wpdb;

        $tabelle       = self::valueTable($tabelle);
        [$wo, $params] = self::sealedWhere($keyId, false);
        $mitVersion    = $tabelle === 'relation_records_history';
        $zeilen        = Query::rows('Mit altem Schlüssel versiegelte Werte lesen', $wpdb->prepare(
            'SELECT id, ' . ($mitVersion ? 'version' : 'NULL AS version') . ', value_text FROM ' . Schema::table($tabelle) . $wo . ' LIMIT %d',
            ...[...$params, $limit]
        ));

        return array_map(static fn (array $z): array => [
            'id'      => (int) $z['id'],
            'version' => $z['version'] === null ? null : (int) $z['version'],
            'text'    => (string) $z['value_text'],
        ], $zeilen ?: []);
    }

    /**
     * The chronicle rows holding a sealed value (`value=enc1:`) that pass `$keep`.
     *
     * @param \Closure(?string, ?string): bool $keep before, after
     * @return list<array{id: int, before_state: ?string, after_state: ?string}>
     */
    public static function journalSealedRows(\Closure $keep, int $limit): array
    {
        global $wpdb;

        $like = '%' . $wpdb->esc_like('value=' . FieldCipher::PREFIX) . '%';
        $aus  = [];

        Query::each('Chronik mit versiegelten Werten lesen', $wpdb->prepare(
            'SELECT id, before_state, after_state FROM ' . Schema::table('changelog') . ' WHERE before_state LIKE %s OR after_state LIKE %s ORDER BY id ASC',
            $like,
            $like
        ), static function (array $z) use (&$aus, $keep, $limit): void {
            $vorher  = $z['before_state'] === null ? null : (string) $z['before_state'];
            $nachher = $z['after_state'] === null ? null : (string) $z['after_state'];

            if (count($aus) < $limit && $keep($vorher, $nachher)) {
                $aus[] = ['id' => (int) $z['id'], 'before_state' => $vorher, 'after_state' => $nachher];
            }
        });

        return $aus;
    }

    /** How many chronicle rows hold a value sealed with this key id. */
    public static function journalSealedWithCount(string $keyId): int
    {
        global $wpdb;

        $like = '%' . $wpdb->esc_like('value=' . FieldCipher::PREFIX . $keyId . ':') . '%';

        return (int) Query::value('Chronik je Schlüssel zählen', $wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Schema::table('changelog') . ' WHERE before_state LIKE %s OR after_state LIKE %s',
            $like,
            $like
        ));
    }

    /** @return list<int> the edges on which some live or history row holds a sealed text (D-1578) */
    public static function sealedEdges(): array
    {
        global $wpdb;

        $like = $wpdb->esc_like(FieldCipher::PREFIX) . '%';
        $ids  = Query::column('Kanten mit versiegelten Werten lesen', $wpdb->prepare(
            'SELECT DISTINCT relation_id FROM ' . Schema::table('relation_records') . ' WHERE value_text LIKE %s
             UNION SELECT DISTINCT relation_id FROM ' . Schema::table('relation_records_history') . ' WHERE value_text LIKE %s',
            $like,
            $like
        ));

        return array_map(intval(...), $ids ?: []);
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

    /** Only the two value tables are ever named here. */
    private static function valueTable(string $tabelle): string
    {
        return $tabelle === 'relation_records_history' ? 'relation_records_history' : 'relation_records';
    }

    /** @return array{0: string, 1: list<string>} */
    private static function sealedWhere(string $keyId, bool $with): array
    {
        global $wpdb;

        $mit = $wpdb->esc_like(FieldCipher::PREFIX . $keyId . ':') . '%';

        return $with
            ? [' WHERE value_text LIKE %s', [$mit]]
            : [' WHERE value_text LIKE %s AND value_text NOT LIKE %s', [$wpdb->esc_like(FieldCipher::PREFIX) . '%', $mit]];
    }
}
