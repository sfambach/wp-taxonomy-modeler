<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Exception\ConcurrentChange;
use Taxmod\Core\Model\Setting\SettingsObject;
use Taxmod\Core\Model\Setting\SettingsValue;
use Taxmod\Core\Repository\SettingsRepository;

/**
 * `settings_object` und `settings_value` über `$wpdb` — Fassung 47, Schritt 3 des Bauplans.
 *
 * ⚠️ **Jede Tabelle zählt ihre Ids selbst** (Anforderung 4.2.1): *`AUTO_INCREMENT` je Tabelle, kein
 * gemeinsamer Raum, keine Vergabestelle — sein Wort: «jede tabelle kann ihren eigenen
 * schlüsselzähler haben».* **Und jede Änderung hebt die alte Zeile auf** ({@see Shadow::keepOne()},
 * [D-536](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *Ein Verweis ist eine Spalte je Zieltabelle (4.2.2): `node_id`, `settings_object_id`,
 * `relation_id` als Träger und Zusatz; `wert_knoten_id`, `wert_settings_object_id` als Wert. Die
 * Fremdschlüssel legt {@see Schema::constrainSettingsValues()}.*
 *
 * @see docs/einstellungen-anforderungen.md
 */
final class WpdbSettingsRepository implements SettingsRepository
{
    private const VALUE_COLUMNS = 'id, version, node_id, settings_object_id, relation_id, klasse, attribut, position, aktiv, wert_int, wert_decimal, wert_text, wert_knoten_id, wert_settings_object_id';

    public function addObject(SettingsObject $object): SettingsObject
    {
        global $wpdb;

        $wpdb->insert(Schema::table('settings_object'), ['version' => $object->version, 'klasse' => $object->klasse], ['%d', '%s']);

        return $object->withAssignedId((int) $wpdb->insert_id);
    }

    public function findObject(int $id): ?SettingsObject
    {
        $rows = $this->objectsByIds([$id]);

        return $rows[$id] ?? null;
    }

    public function objectsByIds(array $ids): array
    {
        global $wpdb;

        if ($ids === []) {
            return [];
        }

        $rows = Query::rows('Einstellungsobjekte lesen', $wpdb->prepare(
            'SELECT id, version, klasse FROM ' . Schema::table('settings_object')
            . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')',
            ...array_map(intval(...), $ids)
        ));

        $aus = [];

        foreach ($rows as $row) {
            $aus[(int) $row['id']] = SettingsObject::fromStorage((int) $row['id'], (int) $row['version'], (string) $row['klasse']);
        }

        return $aus;
    }

    public function addValue(SettingsValue $value): SettingsValue
    {
        global $wpdb;

        [$spalten, $formate] = $this->columnsOf($value);

        $wpdb->insert(Schema::table('settings_value'), $spalten, $formate);

        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('Einstellungszeile schreiben: ' . $wpdb->last_error);
        }

        return $value->stored((int) $wpdb->insert_id, $value->version);
    }

    public function saveValue(SettingsValue $value, int $expectedVersion): void
    {
        global $wpdb;

        $id = $value->id ?? throw new \LogicException('Eine Zeile ohne Id kann nicht neu geschrieben werden.');

        // ⚠️ **Zuerst die alte Zeile in den Schatten, dann schreiben** ([D-536](../../../docs/NewConcept/90-decision-log.md)).
        Shadow::keepOne('settings_value', $id);

        [$spalten, $formate] = $this->columnsOf($value);

        $written = $wpdb->update(
            Schema::table('settings_value'),
            $spalten,
            ['id' => $id, 'version' => $expectedVersion],
            $formate,
            ['%d', '%d']
        );

        if ($written === 1) {
            return;
        }

        $current = $this->findValue($id);

        throw ConcurrentChange::on($id, $expectedVersion, $current?->version ?? 0);
    }

    public function findValue(int $id): ?SettingsValue
    {
        global $wpdb;

        $row = Query::row('Einstellungszeile lesen', $wpdb->prepare(
            'SELECT ' . self::VALUE_COLUMNS . ' FROM ' . Schema::table('settings_value') . ' WHERE id = %d',
            $id
        ));

        return $row === null ? null : $this->hydrate($row);
    }

    public function valuesOfNodes(array $nodeIds): array
    {
        return $this->valuesWhere('node_id', $nodeIds);
    }

    public function valuesOfObjects(array $objectIds): array
    {
        return $this->valuesWhere('settings_object_id', $objectIds);
    }

    public function valuesReferring(array $nodeIds): array
    {
        global $wpdb;

        if ($nodeIds === []) {
            return [];
        }

        $rows = Query::rows('Zeilen mit Verweis lesen', $wpdb->prepare(
            'SELECT ' . self::VALUE_COLUMNS . ' FROM ' . Schema::table('settings_value')
            . ' WHERE wert_knoten_id IN (' . implode(',', array_fill(0, count($nodeIds), '%d')) . ') ORDER BY id',
            ...array_map(intval(...), $nodeIds)
        ));

        return array_map($this->hydrate(...), $rows);
    }

    public function forgetValue(int $id): ?int
    {
        global $wpdb;

        $version = Query::value('Version der Einstellungszeile vor dem Wandern lesen', $wpdb->prepare(
            'SELECT version FROM ' . Schema::table('settings_value') . ' WHERE id = %d',
            $id
        ));

        if ($version === null) {
            return null;
        }

        Shadow::keepOne('settings_value', $id, true);
        $wpdb->delete(Schema::table('settings_value'), ['id' => $id], ['%d']);

        return (int) $version;
    }

    public function forgetObject(int $id): ?int
    {
        global $wpdb;

        $object = $this->findObject($id);

        if ($object === null) {
            return null;
        }

        // ⚠️ *Erst die Zeilen, dann das Objekt — der Fremdschlüssel `settings_object_id` lässt es
        // andersherum nicht zu, und das ist gewollt (4.6.4: ein Objekt wandert mit seinen Zeilen).*
        foreach (Query::column('Zeilen des Objekts lesen', $wpdb->prepare(
            'SELECT id FROM ' . Schema::table('settings_value') . ' WHERE settings_object_id = %d',
            $id
        )) as $valueId) {
            $this->forgetValue((int) $valueId);
        }

        Shadow::keepOne('settings_object', $id, true);
        $wpdb->delete(Schema::table('settings_object'), ['id' => $id], ['%d']);

        return $object->version;
    }

    public function countValues(): int
    {
        global $wpdb;

        return (int) Query::value('Einstellungszeilen zählen', 'SELECT COUNT(*) FROM ' . Schema::table('settings_value'));
    }

    public function countObjects(): int
    {
        global $wpdb;

        return (int) Query::value('Einstellungsobjekte zählen', 'SELECT COUNT(*) FROM ' . Schema::table('settings_object'));
    }

    /**
     * @param  list<int> $ids
     * @return array<int, list<SettingsValue>>
     */
    private function valuesWhere(string $column, array $ids): array
    {
        global $wpdb;

        $aus = array_fill_keys(array_map(intval(...), $ids), []);

        if ($ids === []) {
            return $aus;
        }

        $rows = Query::rows('Einstellungszeilen lesen', $wpdb->prepare(
            'SELECT ' . self::VALUE_COLUMNS . ' FROM ' . Schema::table('settings_value')
            . " WHERE {$column} IN (" . implode(',', array_fill(0, count($ids), '%d')) . ')'
            . ' ORDER BY klasse, attribut, position, id',
            ...array_map(intval(...), $ids)
        ));

        foreach ($rows as $row) {
            $aus[(int) $row[$column]][] = $this->hydrate($row);
        }

        return $aus;
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function columnsOf(SettingsValue $value): array
    {
        // ⚠️ *`NULL` muss ein echtes NULL sein — `$wpdb->insert()` schreibt es für `null`, was
        // `prepare('%d', null)` nicht täte. Dieselbe Falle, die `implemented_by` schon hatte.*
        return [[
            'version'                 => $value->version,
            'node_id'                 => $value->nodeId,
            'settings_object_id'      => $value->objectId,
            'relation_id'             => $value->relationId,
            'klasse'                  => $value->klasse,
            'attribut'                => $value->attribut,
            'position'                => $value->position,
            'aktiv'                   => $value->aktiv ? 1 : 0,
            'wert_int'                => $value->value->int,
            'wert_decimal'            => $value->value->decimal,
            'wert_text'               => $value->value->text,
            'wert_knoten_id'          => $value->value->reference,
            'wert_settings_object_id' => $value->valueObjectId,
        ], ['%d', '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%d']];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): SettingsValue
    {
        return SettingsValue::fromStorage(
            (int) $row['id'],
            (int) $row['version'],
            $row['node_id'] === null ? null : (int) $row['node_id'],
            $row['settings_object_id'] === null ? null : (int) $row['settings_object_id'],
            $row['relation_id'] === null ? null : (int) $row['relation_id'],
            (string) $row['klasse'],
            (string) $row['attribut'],
            (int) $row['position'],
            (bool) (int) $row['aktiv'],
            $row['wert_int'] === null ? null : (int) $row['wert_int'],
            $row['wert_decimal'] === null ? null : rtrim(rtrim((string) $row['wert_decimal'], '0'), '.'),
            $row['wert_text'] === null ? null : (string) $row['wert_text'],
            $row['wert_knoten_id'] === null ? null : (int) $row['wert_knoten_id'],
            $row['wert_settings_object_id'] === null ? null : (int) $row['wert_settings_object_id'],
        );
    }
}
