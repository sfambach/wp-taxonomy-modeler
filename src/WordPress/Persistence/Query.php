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
     * Was in diesem Seitenaufruf schon gelesen wurde — Art und Anweisung ⇒ Antwort ([D-814](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *Gemessen am 2026-09-15: auf der schwersten Knotenseite waren **367 von 550** Abfragen wörtlich dieselbe Anweisung ein
     * zweites Mal. **Jede schreibende Anweisung leert das Gedächtnis** ({@see self::watchWrites()}), also liest niemand nach einem
     * Schreiben einen alten Stand; es lebt nur bis zum Ende des Aufrufs und ist kein zweiter Ort für eine Tatsache.*
     *
     * @var array<string, mixed>
     */
    private static array $read = [];

    private static bool $watching = false;

    /** Wie tief gerade in {@see self::remembering()} gearbeitet wird — ausserhalb wird nichts behalten. */
    private static int $depth = 0;

    /** Das Gedächtnis leeren — jede schreibende Anweisung tut es von selbst. */
    public static function forget(): void
    {
        self::$read = [];
    }

    /**
     * Eine Arbeit, während der Gelesenes behalten wird — vorher und nachher ist das Gedächtnis leer.
     *
     * ⚠️ *Nur für die Dauer eines Zeichenlaufs und nicht für den ganzen Prozess: ein Wächter schreibt über einen **zweiten** Prozess und
     * liest danach im ersten — dessen Schreiben sieht der Filter hier nicht. Gemessen am 2026-09-15 an `labels-page-save`.*
     *
     * @template T
     * @param  callable(): T $work
     * @return T
     */
    public static function remembering(callable $work): mixed
    {
        if (self::$depth === 0) {
            self::forget();
        }

        self::$depth++;

        try {
            return $work();
        } finally {
            self::$depth--;

            if (self::$depth === 0) {
                self::forget();
            }
        }
    }

    /**
     * Zeilen lesen. Wirft, wenn die Abfrage scheitert — nie eine leere Liste als Ersatz.
     *
     * @param string $doing Was gerade versucht wurde, für die Fehlermeldung.
     * @param string|null $sql Die fertige Anweisung, meist aus `$wpdb->prepare()`.
     * @return list<array<string, mixed>>
     */
    public static function rows(string $doing, ?string $sql): array
    {
        self::statementIsUsable($doing, $sql);

        /** @var list<array<string, mixed>> */
        return self::remembered('rows', (string) $sql, static function () use ($doing, $sql): array {
            global $wpdb;

            $wpdb->last_error = '';
            $rows             = $wpdb->get_results($sql, ARRAY_A);

            self::nothingWentWrong($doing);

            return $rows ?: [];
        });
    }

    /**
     * Eine Zeile lesen, oder `null`, wenn es sie **wirklich** nicht gibt.
     *
     * @return array<string, mixed>|null
     */
    public static function row(string $doing, ?string $sql): ?array
    {
        self::statementIsUsable($doing, $sql);

        /** @var array<string, mixed>|null */
        return self::remembered('row', (string) $sql, static function () use ($doing, $sql): ?array {
            global $wpdb;

            $wpdb->last_error = '';
            $row              = $wpdb->get_row($sql, ARRAY_A);

            self::nothingWentWrong($doing);

            return $row;
        });
    }

    /**
     * Eine einzelne Spalte lesen.
     *
     * @return list<string>
     */
    public static function column(string $doing, ?string $sql): array
    {
        self::statementIsUsable($doing, $sql);

        /** @var list<string> */
        return self::remembered('column', (string) $sql, static function () use ($doing, $sql): array {
            global $wpdb;

            $wpdb->last_error = '';
            $values           = $wpdb->get_col($sql);

            self::nothingWentWrong($doing);

            return array_map(strval(...), $values ?: []);
        });
    }

    /** Einen einzelnen Wert lesen, oder `null`, wenn es ihn **wirklich** nicht gibt. */
    public static function value(string $doing, ?string $sql): ?string
    {
        self::statementIsUsable($doing, $sql);

        /** @var string|null */
        return self::remembered('value', (string) $sql, static function () use ($doing, $sql): ?string {
            global $wpdb;

            $wpdb->last_error = '';
            $value            = $wpdb->get_var($sql);

            self::nothingWentWrong($doing);

            return $value === null ? null : (string) $value;
        });
    }

    /**
     * Eine Antwort aus dem Gedächtnis, sonst lesen und behalten — eine gescheiterte Lesung wirft und wird nicht behalten.
     *
     * @param 'rows'|'row'|'column'|'value' $kind
     * @param callable(): mixed             $read
     */
    private static function remembered(string $kind, string $sql, callable $read): mixed
    {
        if (self::$depth === 0) {
            return $read();
        }

        self::watchWrites();

        $key = $kind . "\0" . $sql;

        if (array_key_exists($key, self::$read)) {
            return self::$read[$key];
        }

        return self::$read[$key] = $read();
    }

    /**
     * ⚠️ *Über den Filter `query`, den `$wpdb` für **jede** Anweisung ruft — auch für `insert()`, `update()`, `delete()` und
     * `dbDelta` — und nicht über {@see self::run()} allein: die Speicher schreiben auch an `run()` vorbei.*
     */
    private static function watchWrites(): void
    {
        if (self::$watching || ! function_exists('add_filter')) {
            return;
        }

        self::$watching = true;

        add_filter('query', static function (string $sql): string {
            if (preg_match('/^\s*(SELECT|WITH|SHOW|EXPLAIN|DESCRIBE)\b/i', $sql) !== 1) {
                self::forget();
            }

            return $sql;
        });
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
