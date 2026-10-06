<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

/**
 * Der Vorfahrenweg jedes Knotens als abgeleitete Tabelle `(id, path)` — eine Anweisung, ohne `WITH RECURSIVE` ([D-910](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ```mermaid
 * flowchart LR
 *   N["Knoten n0"] -->|parent_node_id| P1["p1"] -->|parent_node_id| P2["p2"] -.->|…| PD["p MAX_DEPTH"]
 *   PD --> W["path = CONCAT_WS('.', pD … p1, n0)"]
 * ```
 *
 * ⚠️ **Warum keine Rekursion:** *fambach.net läuft auf MySQL 5.7, und `WITH RECURSIVE` gibt es erst ab 8.0. Statt
 * eines rekursiven Ausdrucks steigt jeder Knoten über eine feste Kette von Selbstverknüpfungen auf — ebenfalls
 * **eine** Anweisung (`CD-7`), auf jedem Server gleich.*
 *
 * ⚠️ **Die Tiefe ist begrenzt.** *Ein Knoten tiefer als {@see self::MAX_DEPTH} erreicht in der Kette keine Wurzel
 * und fällt heraus wie ein verwaister — gemessen am 2026-10-06 ist der tiefste Knoten auf Ebene 7.
 * `ancestry-depth-check` wird rot, bevor die Grenze erreicht ist.*
 *
 * ⚠️ *Ein Knoten zählt nur, wenn sein oberster Vorfahr `parent_node_id IS NULL` hat — wie beim rekursiven Abstieg
 * von der Wurzel: ein Zeiger ins Leere oder ein Kreis erreicht keine Wurzel und hat keinen Weg.*
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class Ancestry
{
    /** So viele Ebenen unter der Wurzel trägt der Weg. */
    public const MAX_DEPTH = 32;

    /** Die abgeleitete Tabelle `(id, path)`, in Klammern — für `FROM … a` oder `JOIN … a`. */
    public static function paths(?string $nodes = null): string
    {
        $nodes ??= Schema::table('nodes');

        $joins  = '';
        $chain  = [];
        $rooted = ['n0.parent_node_id IS NULL'];

        for ($level = 1; $level <= self::MAX_DEPTH; $level++) {
            $below   = $level === 1 ? 'n0' : 'p' . ($level - 1);
            $joins  .= " LEFT JOIN {$nodes} p{$level} ON p{$level}.id = {$below}.parent_node_id";
            $chain[] = "p{$level}.id";
            $rooted[] = "(p{$level}.id IS NOT NULL AND p{$level}.parent_node_id IS NULL)";
        }

        return "(SELECT n0.id AS id, CONCAT_WS('.', " . implode(', ', array_reverse($chain)) . ', n0.id) AS path'
            . " FROM {$nodes} n0{$joins}"
            . ' WHERE ' . implode(' OR ', $rooted) . ')';
    }

    /**
     * Knoten und alles unter ihnen, als Anweisung, die Nummern liefert — auch unter einem Knoten ohne Weg zur Wurzel.
     *
     * ⚠️ *Ohne die Wurzelbedingung aus {@see self::paths()}: ein verwaister Ast soll sich ganz entfernen lassen, wie mit
     * dem rekursiven Abstieg vom Knoten selbst, den dies ersetzt.*
     *
     * @param list<int> $roots
     */
    public static function descendants(array $roots, ?string $nodes = null): string
    {
        $nodes ??= Schema::table('nodes');
        $in     = $roots === [] ? 'NULL' : implode(', ', array_map('intval', $roots));

        $joins = '';
        $hits  = ["n0.id IN ({$in})"];

        for ($level = 1; $level <= self::MAX_DEPTH; $level++) {
            $below  = $level === 1 ? 'n0' : 'p' . ($level - 1);
            $joins .= " LEFT JOIN {$nodes} p{$level} ON p{$level}.id = {$below}.parent_node_id";
            $hits[] = "p{$level}.id IN ({$in})";
        }

        return "SELECT n0.id FROM {$nodes} n0{$joins} WHERE " . implode(' OR ', $hits);
    }

    /** Die Ebene einer Zeile `a` aus {@see self::paths()}: die Wurzel ist 0. */
    public static function depth(): string
    {
        return "(LENGTH(a.path) - LENGTH(REPLACE(a.path, '.', '')))";
    }
}
