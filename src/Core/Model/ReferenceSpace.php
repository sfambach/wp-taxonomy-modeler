<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * Worauf ein Verweis zeigt — auf einen **Knoten** oder auf einen **Datensatz**.
 *
 * ⚠️ **Ein Fremdschlüssel nennt seine Zieltabelle; kann eine Spalte auf mehr als eine zeigen,
 * nennt eine zweite Spalte den Raum** ([`package.md` §6](../../../docs/pakete/modelltabellen/package.md)) —
 * so wie `changelog.owner_kind` es seit jeher tut. *Solange alle Tabellen aus `identities` zogen,
 * war eine Id für sich eindeutig; sobald jede Tabelle ihren eigenen Id-Raum hat
 * ([D-164](../../../docs/NewConcept/90-decision-log.md), TASK-004), gibt es Knoten 5 **und**
 * Datensatz 5 — und `relation_records.value_ref` allein sagt dann nicht mehr, welchen es meint.*
 *
 * @see docs/pakete/modelltabellen/package.md
 */
enum ReferenceSpace: string
{
    /** Ein Knoten des Modells — `nodes.id`. */
    case Node = 'node';

    /** Eine Ausprägung — `records.id`. */
    case Record = 'record';
    // ⚠️ *Ein Verweis auf ein Feld — nur im Einstellungsmodell, als Attributtyp «Verweis auf ein Feld» (D-752).*
    case Relation = 'relation';
}
