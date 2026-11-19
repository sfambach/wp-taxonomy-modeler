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

    /** Wie viele schreibende Anweisungen dieser Aufruf seit dem ersten {@see self::writes()} gesehen hat. */
    private static int $written = 0;

    /**
     * Ein Stand, der sich mit jeder schreibenden Anweisung ändert — wer über mehrere Stücke einer Seite hinweg etwas Gelesenes
     * behält, vergleicht ihn und liest nach einem Schreiben neu.
     */
    public static function writes(): int
    {
        self::watchWrites();

        return self::$written;
    }

    /** Das Gedächtnis leeren — jede schreibende Anweisung tut es von selbst. */
    public static function forget(): void
    {
        self::$read = [];
    }

    /** @var array{0: float, 1: float} seconds this request waited for its reads and writes, and for its streamed reads */
    private static array $waited = [0.0, 0.0];

    /**
     * Where the time of a page went, as far as this class saw it — for the page times (addendum to D-1482): the milliseconds
     * spent waiting for statements, and those spent in streamed reads ({@see self::each()}, the folding of each row included).
     * What WordPress itself asked is not in it.
     *
     * @return array{0: int, 1: int}
     */
    public static function waited(): array
    {
        return [(int) round(self::$waited[0] * 1000), (int) round(self::$waited[1] * 1000)];
    }

    /**
     * @template T
     * @param callable(): T $ask
     * @return T
     */
    private static function timed(int $which, callable $ask): mixed
    {
        $from = microtime(true);

        try {
            return $ask();
        } finally {
            self::$waited[$which] += microtime(true) - $from;
        }
    }

    /** How many statements this request has sent to the database so far — for the drawing times of the developer mode (Ladezeit S0). */
    public static function asked(): int
    {
        global $wpdb;

        return isset($wpdb->num_queries) ? (int) $wpdb->num_queries : 0;
    }

    /**
     * What one statement costs before it does anything: the middle of `$times` empty questions, in microseconds — for the page
     * times (D-1482). On a host whose database is another machine this is what every statement of a page pays.
     */
    public static function roundTripMicroseconds(int $times = 15): int
    {
        global $wpdb;

        $took = [];

        for ($i = 0; $i < max(1, $times); $i++) {
            $start = hrtime(true);
            $wpdb->query('SELECT 1');
            $took[] = (int) ((hrtime(true) - $start) / 1000);
        }

        sort($took);

        return $took[intdiv(count($took), 2)];
    }

    /** Ob gerade behalten wird — dann lohnt es, eine Frage breiter zu stellen und die Antwort für die nächsten mitzunehmen. */
    public static function isRemembering(): bool
    {
        return self::$depth > 0;
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
     * Zeilen lesen und jede sofort weiterreichen, ohne die Antwort zu halten ([D-1489](../../../docs/NewConcept/90-decision-log.md)).
     *
     * ⚠️ *Für die eine Lesung, deren Antwort Zehntausende Zeilen hat: als Liste gehalten kostete jede Wertzeile rund 1600 Byte
     * in der Spitze, und das Lesegedächtnis hielt die Liste ein zweites Mal. **Nicht behalten** — wer so liest, behält sein
     * gefaltetes Ergebnis selbst. **`$each` darf die Datenbank nicht fragen**: solange die Antwort strömt, ist die Verbindung
     * belegt. Ohne mysqli (SQLite in der Cloud) fällt der Weg auf {@see self::rows()} zurück.*
     *
     * @param callable(array<string, string|null>): void $each
     */
    public static function each(string $doing, ?string $sql, callable $each): void
    {
        self::statementIsUsable($doing, $sql);

        global $wpdb;

        $link = $wpdb->dbh ?? null;

        if (! $link instanceof \mysqli) {
            array_map($each, self::rows($doing, $sql));

            return;
        }

        $wpdb->flush();
        $wpdb->last_error = '';
        // Derselbe Filter, den `$wpdb` für jede Anweisung ruft: wer Anweisungen zählt oder mitliest, sieht auch diese.
        $sql = (string) apply_filters('query', (string) $sql);

        self::timed(1, static function () use ($link, $sql, $doing, $each, $wpdb): void {
            $result = mysqli_query($link, $sql, MYSQLI_USE_RESULT);
            $wpdb->num_queries++;

            if (! $result instanceof \mysqli_result) {
                $wpdb->last_error = mysqli_error($link);
                self::nothingWentWrong($doing);

                return;
            }

            try {
                while (($row = mysqli_fetch_assoc($result)) !== null && $row !== false) {
                    $each($row);
                }
            } finally {
                mysqli_free_result($result);
            }

            // *A stream that breaks off (the connection lost half way) ends the loop like its last row does: said, not swallowed —
            // whoever folds the rows would otherwise work with a part of them.*
            if (mysqli_errno($link) !== 0) {
                $wpdb->last_error = mysqli_error($link);
                self::nothingWentWrong($doing);
            }
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
        // D-1485: a locking read is the check at the point of writing (D-1148) — it asks the database every time, never the memory.
        if (self::$depth === 0 || preg_match('/\bFOR\s+UPDATE\s*$/i', $sql) === 1) {
            return self::timed(0, $read);
        }

        self::watchWrites();

        $key = $kind . "\0" . $sql;

        if (array_key_exists($key, self::$read)) {
            return self::$read[$key];
        }

        return self::$read[$key] = self::timed(0, $read);
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
                self::$written++;
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
        $affected         = self::timed(0, static fn (): int|bool => $wpdb->query($sql));

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
