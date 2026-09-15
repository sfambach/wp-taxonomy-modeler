<?php declare(strict_types=1);

namespace Taxmod\Core\Repository;

use Taxmod\Core\Model\Setting\SettingsObject;
use Taxmod\Core\Model\Setting\SettingsValue;

/**
 * Die zwei Tabellen des Einstellungsmodells — `settings_object` und `settings_value`
 * ([D-712](../../../docs/NewConcept/90-decision-log.md), Anforderung 4).
 *
 * ⚠️ **Jede Tabelle zählt ihre Ids selbst; Verweise sind Fremdschlüssel** (4.2) — und jede
 * Änderung hebt die alte Zeile in den Schatten (4.6.1). *Löschen ist Wandern.*
 *
 * ⚠️ *Gelesen wird in Mengen — je Knoten **alle** Zeilen, samt derer mit Kante — damit die Auflösung
 * (5.7) für einen ganzen Bildschirm eine Abfrage braucht und nicht eine je Zeile (`CD-7`).*
 *
 * @see docs/einstellungen-anforderungen.md
 */
interface SettingsRepository
{
    /** Ein Objekt anlegen; die Id vergibt die Tabelle. */
    public function addObject(SettingsObject $object): SettingsObject;

    public function findObject(int $id): ?SettingsObject;

    /**
     * @param  list<int> $ids
     * @return array<int, SettingsObject> nach Id
     */
    public function objectsByIds(array $ids): array;

    /** Eine Zeile anlegen; die Id vergibt die Tabelle. Gibt die Zeile mit Id zurück. */
    public function addValue(SettingsValue $value): SettingsValue;

    /**
     * Eine bestehende Zeile neu schreiben — die alte Fassung geht in den Schatten.
     *
     * @throws \Taxmod\Core\Exception\ConcurrentChange wenn die Zeile nicht mehr in `$expectedVersion` steht
     */
    public function saveValue(SettingsValue $value, int $expectedVersion): void;

    public function findValue(int $id): ?SettingsValue;

    /**
     * Alle Zeilen, die an diesen Knoten hängen — die am Knoten **und** die an einer seiner Kanten.
     *
     * @param  list<int> $nodeIds
     * @return array<int, list<SettingsValue>> nach Knoten-Id, je Liste in Adresse/Position-Reihenfolge
     */
    public function valuesOfNodes(array $nodeIds): array;

    /**
     * Alle Zeilen in diesen Objekten — auch die an einer Kante überschriebenen.
     *
     * @param  list<int> $objectIds
     * @return array<int, list<SettingsValue>> nach Objekt-Id
     */
    public function valuesOfObjects(array $objectIds): array;

    /**
     * Alle Zeilen, die auf diesen Knoten **verweisen** (`wert_knoten_id`) — wer mitwandert, wenn er
     * geparkt wird (4.6.2).
     *
     * @param  list<int> $nodeIds
     * @return list<SettingsValue>
     */
    public function valuesReferring(array $nodeIds): array;

    /**
     * Die lebenden Zeilen, die eines dieser Objekte als Wert nennen — ob ein Objekt nach dem Leeren des Papierkorbs noch gebraucht wird
     * ([D-813](../../../docs/NewConcept/90-decision-log.md)).
     *
     * @param  list<int> $objectIds
     * @return list<SettingsValue>
     */
    public function valuesNamingObjects(array $objectIds): array;

    /** Eine Zeile in den Schatten wandern lassen. Gibt die Version zurück, mit der sie ging, oder `null`. */
    public function forgetValue(int $id): ?int;

    /** Ein Objekt samt seinen Zeilen in den Schatten wandern lassen (4.6.4). */
    public function forgetObject(int $id): ?int;

    public function countValues(): int;

    public function countObjects(): int;
}
