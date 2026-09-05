<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

/**
 * Eine Lesung, die scheitert, wenn sie scheitert — statt leer zu antworten.
 *
 * ⚠️ **Der Grund ist gemessen und hat Geld gekostet.** *Beim Umbau auf `sort_order` (TASK-012)
 * blieben zwei `ORDER BY r.position` stehen. Die Kinderabfrage antwortete daraufhin **leer statt
 * zu scheitern**; jedes Gerüst prüft seine eigene Arbeit, indem es die vorhandenen Kinder nach
 * Namen durchsieht, fand keine — und legte alles ein zweites Mal an: **38 Knoten und 54 Kanten
 * Rückstand in einem einzigen Durchlauf.** Kein Wächter hat es gefunden.*
 *
 * ```mermaid
 * flowchart LR
 *   A["kaputte Abfrage"] --> B["$wpdb liefert null"]
 *   B --> C["?: [] — leere Liste"]
 *   C --> D["Aufrufer: «gibt es noch nicht»"]
 *   D --> E["legt es ein zweites Mal an"]
 * ```
 *
 * **Die Klasse von Fehlern ist nicht dieser eine Tippfehler: eine leere Antwort und eine kaputte
 * Abfrage sehen gleich aus.** `$wpdb` meldet einen Fehler nicht von selbst — `get_results()` gibt
 * für beides `null`, und `?: []` macht daraus dieselbe leere Liste. Wer lesend etwas *sucht*, muss
 * darum nach jeder Anweisung `last_error` mitlesen. Das tut diese Klasse an einer Stelle, damit es
 * nicht dreissigmal einzeln richtig gemacht werden muss (`CD-7`s Geist: einmal gelöst, alle nutzen es).
 *
 * ⚠️ **Und die zweite stille Tür ist `prepare()` selbst**: bei falscher Zahl von Platzhaltern gibt
 * es `null` oder `''` zurück, ohne zu werfen — `get_results(null)` ist wieder die leere Liste.
 * Deshalb wird auch die Anweisung geprüft, bevor sie überhaupt läuft.
 *
 * @see docs/NewConcept/50-wordpress-persistence.md
 */
final class Query
{
    /**
     * Zeilen lesen. Wirft, wenn die Abfrage scheitert — nie eine leere Liste als Ersatz.
     *
     * @param string $doing Was gerade versucht wurde, für die Fehlermeldung.
     * @param string|null $sql Die fertige Anweisung, meist aus `$wpdb->prepare()`.
     * @return list<array<string, mixed>>
     */
    public static function rows(string $doing, ?string $sql): array
    {
        global $wpdb;

        self::statementIsUsable($doing, $sql);

        $wpdb->last_error = '';
        $rows             = $wpdb->get_results($sql, ARRAY_A);

        self::nothingWentWrong($doing);

        /** @var list<array<string, mixed>> */
        return $rows ?: [];
    }

    /**
     * Eine Zeile lesen, oder `null`, wenn es sie **wirklich** nicht gibt.
     *
     * @return array<string, mixed>|null
     */
    public static function row(string $doing, ?string $sql): ?array
    {
        global $wpdb;

        self::statementIsUsable($doing, $sql);

        $wpdb->last_error = '';
        $row              = $wpdb->get_row($sql, ARRAY_A);

        self::nothingWentWrong($doing);

        /** @var array<string, mixed>|null */
        return $row;
    }

    /**
     * Eine einzelne Spalte lesen.
     *
     * @return list<string>
     */
    public static function column(string $doing, ?string $sql): array
    {
        global $wpdb;

        self::statementIsUsable($doing, $sql);

        $wpdb->last_error = '';
        $values           = $wpdb->get_col($sql);

        self::nothingWentWrong($doing);

        return array_map(strval(...), $values ?: []);
    }

    /** Einen einzelnen Wert lesen, oder `null`, wenn es ihn **wirklich** nicht gibt. */
    public static function value(string $doing, ?string $sql): ?string
    {
        global $wpdb;

        self::statementIsUsable($doing, $sql);

        $wpdb->last_error = '';
        $value            = $wpdb->get_var($sql);

        self::nothingWentWrong($doing);

        return $value === null ? null : (string) $value;
    }

    /**
     * Eine schreibende Anweisung. Gibt die Zahl betroffener Zeilen zurück.
     *
     * ⚠️ *`0` betroffene Zeilen und ein Fehler sehen bei `$wpdb->query()` ebenfalls fast gleich aus —
     * `false` gegen `0`, und ein `if (! $getan)` verwechselt beide.*
     */
    public static function run(string $doing, ?string $sql): int
    {
        global $wpdb;

        self::statementIsUsable($doing, $sql);

        $wpdb->last_error = '';
        $affected         = $wpdb->query($sql);

        self::nothingWentWrong($doing);

        if ($affected === false) {
            throw new \RuntimeException($doing . ': die Anweisung ist gescheitert.');
        }

        return (int) $affected;
    }

    /**
     * ⚠️ *Eine leere Anweisung ist kein leeres Ergebnis.* `prepare()` gibt bei falscher Zahl von
     * Platzhaltern `null` oder `''` zurück, und `get_results('')` antwortet mit einer leeren Liste,
     * ohne dass die Datenbank je gefragt wurde.
     */
    private static function statementIsUsable(string $doing, ?string $sql): void
    {
        if ($sql === null || trim($sql) === '') {
            throw new \RuntimeException(
                $doing . ': die Anweisung ist leer — meist eine prepare()-Vorlage, die zu ihren Werten nicht passt.'
            );
        }
    }

    private static function nothingWentWrong(string $doing): void
    {
        global $wpdb;

        if ($wpdb->last_error !== '') {
            throw new \RuntimeException($doing . ': ' . $wpdb->last_error);
        }
    }
}
