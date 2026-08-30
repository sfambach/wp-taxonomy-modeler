<?php declare(strict_types=1);

namespace Taxmod\Core\Model;

/**
 * One value inside a record, addressed by the path that reaches it.
 *
 * ```mermaid
 * flowchart LR
 *   R["record"] --> P["path · 100.101"] --> V["value"]
 *   P --> E["edge_id · 101 · the last step"]
 * ```
 *
 * ⚠️ **The last edge is kept alongside the path** (D-134), and that is what makes the data
 * searchable at all: `WHERE edge_id = … AND value_decimal > 1000` finds every price over a
 * thousand **wherever it sits**, and adding the path narrows it to one attribute. Without the
 * separate column the same question would need a `LIKE` over a text path.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class EdgeRecord
{
    /**
     * @param string $path   Edge ids from the record's model down to this value, `.`-separated.
     * @param int    $edgeId The last step of that path, kept apart so it can be indexed.
     * @param string $locale Empty unless the attribute is declared translatable (D-317).
     */
    public function __construct(
        public readonly int $recordId,
        public readonly string $path,
        public readonly int $edgeId,
        public readonly string $locale,
        public readonly TypedValue $value,
    ) {
    }

    /** A value reached by one edge from the record's own model. */
    public static function direct(int $recordId, int $edgeId, TypedValue $value, string $locale = ''): self
    {
        return new self($recordId, self::pathFor($edgeId), $edgeId, $locale, $value);
    }

    /**
     * Der n-te Wert eines Feldes — mehrere Werte sind mehrere **Pfade**, nicht mehrere Kanten.
     *
     * ⚠️ **Der Speicher sah das immer vor und niemand hat es benutzt:** *der eindeutige Schlüssel
     * heisst `(record_id, path, locale)` und **nicht** `(record_id, edge_id, locale)` — gemessen am
     * 2026-08-30 trugen alle 43 Wertzeilen einen Pfad, der schlicht die Kanten-Id war, und **keiner
     * einen Punkt**. Und `DataEntry`s eigener Docblock sagt es seit langem: «five integers are five
     * **paths** in one record».*
     *
     * ⚠️ **Die Form ist die von `nodes.path`:** *Ids mit Punkten, von aussen nach innen. So setzt sich
     * ein zusammengesetzter Teil fort — `4654.2.7788` ist «der dritte Wert von Feld 4654, darin
     * Feld 7788» — **ohne dass jemand eine zweite Konvention lernen muss**.*
     *
     * ⚠️ *Die laufende Nummer ist kein Index in eine Liste, sondern ein **Name**: wird der zweite von
     * dreien entfernt, bleiben `.1` und `.3`. **Nachrücken würde die Pfade der übrigen ändern**, und an
     * Pfaden hängen verschachtelte Teile.*
     */
    public static function nth(int $recordId, int $edgeId, int $ordinal, TypedValue $value, string $locale = ''): self
    {
        return new self($recordId, self::pathFor($edgeId, $ordinal), $edgeId, $locale, $value);
    }

    /** Wo ein Wert unter seinem Feld sitzt — die eine Stelle, die diese Form kennt. */
    public static function pathFor(int $edgeId, ?int $ordinal = null): string
    {
        return $ordinal === null ? (string) $edgeId : $edgeId . '.' . $ordinal;
    }

    /**
     * Die laufende Nummer aus einem Pfad, oder `null` für den einzigen Wert.
     *
     * ⚠️ *Nur die **erste** Stufe unter dem Feld: `4654.2.7788` gehört dem zweiten Wert von 4654,
     * und was darunter liegt, ist die Sache des Teils.*
     */
    public static function ordinalIn(string $path): ?int
    {
        $stufen = explode('.', $path);

        return isset($stufen[1]) && ctype_digit($stufen[1]) ? (int) $stufen[1] : null;
    }
}
