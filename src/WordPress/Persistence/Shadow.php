<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

/**
 * Schiebt eine lebende Zeile in ihren Schatten, bevor sie überschrieben oder entfernt wird.
 *
 * ⚠️ **[D-536](../../../docs/NewConcept/90-decision-log.md), sein Entwurf, den ich einmal zu schnell
 * abgewiesen hatte:** *«alle Versionen eines Datums in der Datenbank behalten, auch wenn es gelöscht
 * ist, nur mit Löschkennzeichen versehen … und wenn ich zurück will, dann drehe ich einfach die
 * aktuelle Version und habe die Vorgängerversion wieder verfügbar.»*
 *
 * ⚠️ **Warum das das Vorher/Nachher-Problem nicht löst, sondern auflöst:** *er hat es besser gesagt
 * als ich — «es ist immer schwierig, Vorher/Nachher abzuspeichern, weil du auch den Typ brauchst und
 * eventuell die Id und sonst was». **Die alte Zeile ist der Vorher-Zustand**, in ihrer eigenen Form.
 * Gemessen sah das Journal vorher so aus: `to=52250 kind=composition parked=57146 name=__p3 doomed` —
 * Prosa, nicht zurückspielbar.*
 *
 * ⚠️ **Ein Ort und nicht neun** ([D-537](../../../docs/NewConcept/90-decision-log.md)): *gemessen gibt
 * es neun Schreibwege über die vier versionierten Tabellen. **Jeder von ihnen, der sein eigenes
 * Hinüberschieben schriebe, wäre einer, der es beim nächsten Mal vergisst.***
 *
 * ```mermaid
 * flowchart LR
 *   S["schreibender Weg"] --> K["Shadow::keep()"]
 *   K --> I["INSERT … SELECT aus der lebenden Zeile"]
 *   I --> H[("&lt;tabelle&gt;_history")]
 *   S --> W["dann erst schreiben"]
 * ```
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class Shadow
{
    /**
     * Die Spalten, die beide Tabellen teilen — je lebender Tabelle einmal ermittelt.
     *
     * ⚠️ *Aus `information_schema` gelesen statt aufgeschrieben: **eine dritte Liste wäre eine
     * dritte Stelle, die nachläuft.** Dass beide Formen übereinstimmen, sichert
     * `scripts/dev/shadow-shape-check.php`.*
     *
     * @var array<string,list<string>>
     */
    private static array $spalten = [];

    /**
     * Jede Zeile, auf die die Bedingung passt, in den Schatten schreiben.
     *
     * ⚠️ **Vor dem Schreiben aufrufen, nie danach** — *danach steht dort schon der neue Wert, und der
     * Schatten hielte zweimal dasselbe.*
     *
     * ⚠️ *`ON DUPLICATE KEY UPDATE`, weil `(id, version)` derselbe sein kann, wenn ein Weg die
     * Version nicht hochzählt. **Dann gewinnt der letzte gesehene Zustand**, statt dass der Schreibweg
     * an einem doppelten Schlüssel scheitert und der Benutzer eine Ausnahme sieht, wo er einen Wert
     * speichern wollte.*
     *
     * @param  string            $liveTable Name ohne Präfix, aus {@see Schema::LIVE_TABLES}.
     * @param  string            $where     Bedingung mit `%d`/`%s`-Platzhaltern, ohne `WHERE`.
     * @param  list<int|string>  $args      Die Werte dazu.
     * @return int Wie viele Zeilen aufgehoben wurden.
     */
    public static function keep(string $liveTable, string $where, array $args, bool $deleted = false): int
    {
        global $wpdb;

        $schatten = self::shadowFor($liveTable);

        if ($schatten === null) {
            return 0;
        }

        $spalten = self::sharedColumns($liveTable, $schatten);

        if ($spalten === []) {
            return 0;
        }

        $liste = implode(', ', $spalten);

        // ⚠️ **`spalte = VALUES(spalte)`, und die linke Seite hatte ich vergessen.** *Die Abfrage
        // scheiterte an einem Syntaxfehler, `$wpdb->query()` gab `false` zurück, und weil das hier als
        // «null Zeilen» gelesen wurde, **liefen alle Randprüfungen grün, während der Schatten leer
        // blieb** — gemessen am 2026-08-30. Daher unten die Ausnahme.*
        $neu = implode(', ', array_map(static fn (string $s): string => "{$s} = VALUES({$s})", $spalten));

        // ⚠️ *Die Spaltennamen kommen aus `information_schema` und nie von aussen — trotzdem
        // durchgelassen nur, was wie ein Spaltenname aussieht (`CD-6`: nichts Variables ohne
        // Vorbereitung, und was nicht vorbereitbar ist, wird eingeschränkt).*
        $sql = 'INSERT INTO ' . Schema::table($schatten) . " ({$liste}, deleted, archived_at)
                SELECT {$liste}, %d, %s FROM " . Schema::table($liveTable) . " WHERE {$where}
                ON DUPLICATE KEY UPDATE {$neu}, deleted = VALUES(deleted), archived_at = VALUES(archived_at)";

        $written = $wpdb->query($wpdb->prepare(
            $sql,
            $deleted ? 1 : 0,
            gmdate('Y-m-d H:i:s'),
            ...$args
        ));

        // ⚠️ **Eine gescheiterte Aufhebung ist kein leeres Ergebnis** (`CD-10`: niemals ein nacktes
        // `false`). *Genau diese Unterscheidung fehlte hier: `$wpdb->query()` gab `false` für einen
        // Syntaxfehler zurück, das wurde als «null Zeilen» gelesen, und **jede Randprüfung blieb
        // grün, während nichts aufgehoben wurde**. Ein Schatten, der still nichts schreibt, ist
        // schlimmer als keiner — man verlässt sich darauf.*
        if ($written === false || $wpdb->last_error !== '') {
            throw new \RuntimeException(
                "Die Geschichte von «{$liveTable}» liess sich nicht aufheben: " . $wpdb->last_error
            );
        }

        return (int) $written;
    }

    /** Kurzform für den häufigsten Fall: genau eine Zeile, über ihre Id. */
    public static function keepOne(string $liveTable, int $id, bool $deleted = false): int
    {
        return self::keep($liveTable, 'id = %d', [$id], $deleted);
    }

    /** Der Schatten zu einer lebenden Tabelle, oder `null`, wenn sie keinen hat. */
    private static function shadowFor(string $liveTable): ?string
    {
        $stelle = array_search($liveTable, Schema::LIVE_TABLES, true);

        return $stelle === false ? null : (Schema::SHADOW_TABLES[$stelle] ?? null);
    }

    /** @return list<string> */
    private static function sharedColumns(string $liveTable, string $shadowTable): array
    {
        if (isset(self::$spalten[$liveTable])) {
            return self::$spalten[$liveTable];
        }

        global $wpdb;

        // ⚠️ **Hier ist die stille Klasse besonders teuer**: eine leere Spaltenliste archiviert
        // *nichts* und sieht dabei wie ein Erfolg aus. *Darum {@see Query} und nicht `?: []`.*
        $lebend = Query::column('Spalten der lebenden Tabelle lesen', $wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION',
            Schema::table($liveTable)
        ));

        $imSchatten = Query::column('Spalten der Schattentabelle lesen', $wpdb->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            Schema::table($shadowTable)
        ));

        $geteilt = [];

        foreach ($lebend as $name) {
            $name = (string) $name;

            if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
                continue;
            }

            if (in_array($name, Schema::SHADOW_ONLY, true) || ! in_array($name, $imSchatten, true)) {
                continue;
            }

            $geteilt[] = $name;
        }

        // ⚠️ *Keine gemeinsame Spalte heisst nicht «nichts zu sichern», sondern «die Frage kam nicht
        // an». Eine Schattentabelle ohne eine einzige geteilte Spalte gibt es nicht.*
        if ($geteilt === []) {
            throw new \RuntimeException(
                "«{$liveTable}» und «{$shadowTable}» teilen keine einzige Spalte — die Sicherung würde leer laufen."
            );
        }

        return self::$spalten[$liveTable] = $geteilt;
    }

    /**
     * Den gemerkten Spaltenplan vergessen.
     *
     * ⚠️ *Für die Randprüfungen: sie legen Spalten an und entfernen sie wieder, und ein Plan aus dem
     * Lauf davor wäre dann falsch. **Im Betrieb wird sie nie gebraucht**, weil ein Schemaschritt und
     * ein Schreibweg nicht im selben Aufruf liegen.*
     */
    public static function forgetColumnPlan(): void
    {
        self::$spalten = [];
    }
}
