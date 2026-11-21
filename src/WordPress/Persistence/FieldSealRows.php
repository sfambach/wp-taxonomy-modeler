<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Addon\AddonRegistry;
use Taxmod\Core\Addon\ConfidentialAddon;
use Taxmod\Core\Privacy\FieldCipher;

/**
 * The queries of the confidential fields (D-1514): which edges carry «Vertraulich», and the rows still plain on them in
 * the live table, the shadow table and the chronicle. The data layer's part; sealing itself is `Privacy\FieldSeal`'s.
 *
 * @see docs/pakete/vertraulich/konzept.md
 */
final class FieldSealRows
{
    /** @return list<int> the edges with an active «Vertraulich» */
    public static function confidentialRelationIds(): array
    {
        global $wpdb;

        $ids = Query::column('Vertrauliche Kanten lesen', $wpdb->prepare(
            'SELECT DISTINCT v.relation_id FROM ' . Schema::table('settings_value') . ' v
             JOIN ' . Schema::table('settings_object') . ' o ON o.id = v.wert_settings_object_id
             WHERE v.attribut = %s AND v.relation_id IS NOT NULL AND v.aktiv = 1 AND o.klasse = %s',
            AddonRegistry::ATTRIBUTE,
            ConfidentialAddon::class
        ));

        return array_map(intval(...), $ids ?: []);
    }

    /**
     * How many rows of `relation_records` or `relation_records_history` still hold a plain text on these edges.
     *
     * @param list<int> $kanten
     */
    public static function plainCount(string $tabelle, array $kanten): int
    {
        global $wpdb;

        [$wo, $params] = self::plainWhere($kanten);

        return (int) Query::value('Klartext an vertraulichen Kanten zählen', $wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table(self::valueTable($tabelle)) . $wo, ...$params));
    }

    /**
     * Up to `$limit` such rows with their key, edge and text.
     *
     * @param list<int> $kanten
     * @return list<array{id: int, version: ?int, relation_id: int, text: string}>
     */
    public static function plainRows(string $tabelle, array $kanten, int $limit): array
    {
        global $wpdb;

        $tabelle       = self::valueTable($tabelle);
        [$wo, $params] = self::plainWhere($kanten);
        $mitVersion    = $tabelle === 'relation_records_history';
        $zeilen        = Query::rows('Klartext an vertraulichen Kanten lesen', $wpdb->prepare(
            'SELECT id, ' . ($mitVersion ? 'version' : 'NULL AS version') . ', relation_id, value_text FROM ' . Schema::table($tabelle) . $wo . ' LIMIT %d',
            ...[...$params, $limit]
        ));

        return array_map(static fn (array $z): array => [
            'id'          => (int) $z['id'],
            'version'     => $z['version'] === null ? null : (int) $z['version'],
            'relation_id' => (int) $z['relation_id'],
            'text'        => (string) $z['value_text'],
        ], $zeilen ?: []);
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

    /**
     * @param list<int> $kanten
     * @return array{0: string, 1: list<int|string>}
     */
    private static function plainWhere(array $kanten): array
    {
        global $wpdb;

        return [
            ' WHERE relation_id IN (' . implode(',', array_fill(0, count($kanten), '%d')) . ") AND value_text IS NOT NULL AND value_text <> '' AND value_text NOT LIKE %s",
            [...$kanten, $wpdb->esc_like(FieldCipher::PREFIX) . '%'],
        ];
    }
}
