<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

/**
 * Der Abzug des gewachsenen Baums — **eine frische Installation bekommt ihn Zeile für Zeile**.
 *
 * ⚠️ **Der Vollzug von [D-600](../../../docs/NewConcept/90-decision-log.md):** *«eine
 * Neuinstallation entsteht künftig aus einem Abbild des gewachsenen Baums; bis dahin wird die
 * laufende Installation angepasst».* **Der Eigentümer am 2026-09-06 zu `INF-063`:** *«wir sollten
 * den aktuellen bestand einfrieren lass aber alles mit `__` weg das ist dir».*
 *
 * ```mermaid
 * flowchart LR
 *   A["sein Bestand"] -->|"saat-export.php · liest"| B["data/saat.json"]
 *   B -->|"import() · nur in ein leeres Modell"| C["frische Installation"]
 *   C --> D["Gerüste finden alles vor und legen nichts an"]
 * ```
 *
 * ⚠️ **Der Abzug bringt die **Nummern** mit, und das ist sein eigentlicher Zweck.** *Die vier
 * Gerüste suchen am **Namen** und legen an, was sie nicht finden — genau das hat dem Eigentümer
 * dreimal eine deutsche `Adresse` ins Modell gesät, nachdem er sie in `Address` umbenannt hatte
 * (siehe `lib/no-write.php`). **Ein Abzug mit Ids braucht diese Suche nicht.**
 *
 * ⚠️ **Ein Abbild und kein zweites Modell** (`PR-1`). *Die Datei wird **erzeugt** und eingecheckt,
 * nicht gepflegt. Wer sie von Hand ändert, hat eine zweite Quelle der Wahrheit angelegt; wer den
 * Baum ändern will, ändert den Baum und zieht danach neu ab.*
 *
 * ⚠️ **Es wird nur in ein leeres Modell geschrieben.** *Ein bestehender Bestand wird nie
 * überschrieben — auch nicht «ergänzt». Der Abzug ist der Anfangszustand und keine Wanderung.*
 *
 * @see docs/pakete/modelltabellen/package.md
 */
final class SeedImage
{
    public const OPTION = 'taxmod_seed_image';

    /** Raise it only when the image itself is meant to reach installations again. */
    public const VERSION = 1;

    /**
     * Die Tabellen des Abzugs, in **Einfügereihenfolge** und mit ihren Spalten.
     *
     * ⚠️ **Eine feste Liste und kein `SHOW COLUMNS`** (`PR-13`): *sie ist die Definition der
     * Prüfsumme, und eine Prüfsumme, deren Bestandteile sich still ändern können, sagt nichts.
     * {@see self::columnsAreComplete()} wird rot, sobald die Tabelle eine Spalte mehr hat.*
     *
     * ⚠️ *`labels` vor `nodes`, `nodes` vor `relations` — die beiden einzigen Fremdschlüssel
     * zeigen von `relations.from_node_id`/`to_node_id` auf `nodes.id`.*
     *
     * @var array<string, list<string>>
     */
    public const TABLES = [
        'labels'           => ['id', 'version', 'owner_kind', 'icon'],
        'label_texts'      => ['id', 'label_id', 'locale', 'number', 'text_name', 'text_form', 'text_table', 'text_select', 'text_help', 'text_symbol'],
        'nodes'            => ['id', 'version', 'label_id', 'implemented_by', 'parent_node_id', 'sort_order', 'hide', 'klasse'],
        'relations'        => ['id', 'version', 'from_node_id', 'to_node_id', 'kind', 'label_id', 'sort_order', 'multiplicity', 'hide', 'read_only'],
        'node_records'     => ['id', 'version', 'node_id', 'node_version', 'created_at', 'record_type', 'relation_id'],
        'relation_records' => ['id', 'node_record_id', 'relation_id', 'locale', 'position', 'version', 'value_int', 'value_decimal', 'value_text', 'value_date', 'value_ref', 'value_ref_kind'],
    ];

    /**
     * Die Optionen, die **mitkommen** — Muster, `*` steht für den Rest des Namens.
     *
     * ⚠️ **Der Abzug bringt Nummern mit, und die Optionen sind die Zeiger darauf.** *`taxmod_root_id`,
     * die Astoptionen, die Rollen, die gemerkten Rahmenknoten der Renderer und die zwei
     * Einstellungskanten — ohne sie fände eine frische Installation den Baum nicht wieder, den sie
     * gerade bekommen hat, und die Gerüste legten ihn ein zweites Mal an.*
     *
     * ⚠️ *Die vier `*_scaffold`-Fassungen stehen mit dabei, und das ist ihr eigentlicher Zweck hier:
     * sie sagen den Gerüsten, dass ihre Arbeit schon getan ist.*
     *
     * @var list<string>
     */
    public const OPTIONS_IN = [
        'taxmod_root_id',
        'taxmod_trash_id',
        'taxmod_primitives_id',
        'taxmod_installation_id',
        'taxmod_roles_id',
        'taxmod_unit_value_id',
        'taxmod_branch_*',
        'taxmod_role_*',
        'taxmod_render_*',
        'taxmod_setting_edge_*',
        'taxmod_setting_value_edge_*',
        // ⚠️ *Die Notizen des Einheitengerüsts ([D-709](../../../docs/NewConcept/90-decision-log.md)):
        // je Gerüstknoten seine Id — Zeiger wie die Rollen, und aus demselben Grund im Abzug.*
        'taxmod_unit_node_*',
        'taxmod_base_scaffold',
        'taxmod_unit_scaffold',
        'taxmod_composition_scaffold',
        'taxmod_rendering_scaffold',
        'taxmod_neutral_locale',
    ];

    /**
     * Die Optionen, die **ausdrücklich draussen bleiben** — mit ihrem Grund je Zeile.
     *
     * ⚠️ **Zwei Listen und kein Rest** (`PR-4`): *eine Option, die in keiner von beiden steht, lässt
     * `saat-export.php` aufhören. Eine neue Option gehört in den Abzug oder ausdrücklich nicht
     * hinein, und das ist eine Entscheidung, keine Ableitung.*
     *
     * @var array<string, string> Muster => Grund.
     */
    public const OPTIONS_OUT = [
        'taxmod_schema_version'      => 'gehört dem Schema; `Schema::install()` setzt sie selbst',
        'taxmod_seed_image'          => 'die Marke des Abzugs — sie entsteht beim Einspielen',
        'taxmod_testast_*'           => 'die Testäste der Wächter, Präfix `__`',
        'taxmod_*_shape'             => 'Messungen von Wanderungen und Wächtern, kein Modell',
        'taxmod_renderer_choice_drop' => 'das Protokoll einer Wanderung',
        'taxmod_developer'           => 'die Bildschirmeinstellung eines Betreibers, nicht sein Baum',
        // ⚠️ *Am 2026-09-09 aus seinem Bestand gelöscht ([D-703](../../../docs/NewConcept/90-decision-log.md)):
        //  sie stand auf `0`, während gelesen `1` war, und täuschte jeden, der nachsah. **Der Eintrag
        //  bleibt trotzdem**, weil eine ältere Installation sie noch hat und der Abzug sonst dort
        //  abbräche — er kennt keinen Rest.*
        'taxmod_developer_mode'      => 'zurückgezogen: dieselbe Sorte, und ein zweiter Name für dieselbe Sache',
        'taxmod_icon_size'           => 'dieselbe Sorte',
        'taxmod_font_size'           => 'dieselbe Sorte — sie stand nur nie da, weil kein Abzug seit D-397 lief',
        'taxmod_show_trash'          => 'dieselbe Sorte: ob der Papierkorb dasteht, entscheidet ein Betreiber (D-693)',
    ];

    /**
     * Die Optionen, deren Wert eine **Nummer** aus dem Modell ist.
     *
     * ⚠️ *Zeigt eine davon auf etwas, das der Abzug nicht enthält, kommt sie nicht mit — sonst
     * brächte der Abzug einen Zeiger ins Leere.*
     *
     * @var list<string>
     */
    public const OPTIONS_WITH_ID = [
        'taxmod_root_id',
        'taxmod_trash_id',
        'taxmod_primitives_id',
        'taxmod_installation_id',
        'taxmod_roles_id',
        'taxmod_unit_value_id',
        'taxmod_branch_*',
        'taxmod_role_*',
        'taxmod_render_*',
        'taxmod_setting_edge_*',
        'taxmod_setting_value_edge_*',
        'taxmod_unit_node_*',
    ];

    /** Ob ein Name auf ein Muster passt — `*` steht für den Rest. */
    private static function matches(string $name, string $pattern): bool
    {
        $star = strpos($pattern, '*');

        if ($star === false) {
            return $name === $pattern;
        }

        return str_starts_with($name, substr($pattern, 0, $star))
            && str_ends_with($name, substr($pattern, $star + 1));
    }

    public static function optionBelongs(string $name): bool
    {
        foreach (self::OPTIONS_IN as $pattern) {
            if (self::matches($name, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public static function optionStaysOut(string $name): bool
    {
        foreach (array_keys(self::OPTIONS_OUT) as $pattern) {
            if (self::matches($name, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public static function optionCarriesId(string $name): bool
    {
        foreach (self::OPTIONS_WITH_ID as $pattern) {
            if (self::matches($name, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /** Wo der Abzug liegt — eine Datei im Quellbaum, keine Tabelle. */
    public static function file(): string
    {
        return dirname(__DIR__, 3) . '/data/saat.json';
    }

    /**
     * Der Abzug, gelesen — oder `null`, wenn keiner mitgeliefert wurde.
     *
     * @return array<string, mixed>|null
     */
    public static function read(?string $file = null): ?array
    {
        $file ??= self::file();

        if (! is_readable($file)) {
            return null;
        }

        $raw = file_get_contents($file);

        if ($raw === false) {
            throw new \RuntimeException('Der Abzug ist da, lässt sich aber nicht lesen: ' . $file);
        }

        $image = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($image) || ! isset($image['tabellen']) || ! is_array($image['tabellen'])) {
            throw new \RuntimeException('Der Abzug hat nicht die erwartete Form: ' . $file);
        }

        return $image;
    }

    /**
     * Zählung und Prüfsumme über einen Satz Tabellen — **die Zusage spricht in Zahlen, nicht in
     * Namen**.
     *
     * Dieselbe Rechnung für die Datei und für die Datenbank, damit «derselbe Baum» eine Messung
     * ist und kein Augenschein.
     *
     * @param array<string, list<array<string, mixed>>> $tables
     * @return array{zaehlung: array<string, int>, pruefsumme: string}
     */
    public static function shapeOf(array $tables): array
    {
        $canonical = [];
        $counts    = [];

        foreach (self::TABLES as $table => $columns) {
            $rows = $tables[$table] ?? [];

            usort($rows, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);

            $canonical[$table] = array_map(
                static function (array $row) use ($columns): array {
                    $out = [];

                    foreach ($columns as $column) {
                        $value        = $row[$column] ?? null;
                        $out[$column] = $value === null ? null : (string) $value;
                    }

                    return $out;
                },
                $rows
            );

            $counts[$table] = count($rows);
        }

        return [
            'zaehlung'   => $counts,
            'pruefsumme' => hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        ];
    }

    /**
     * Zählung und Prüfsumme des **lebenden** Modells, in derselben Rechnung.
     *
     * @return array{zaehlung: array<string, int>, pruefsumme: string}
     */
    public static function shapeOfModel(): array
    {
        $tables = [];

        foreach (self::TABLES as $table => $columns) {
            $tables[$table] = Query::rows(
                "{$table} für die Prüfsumme lesen",
                'SELECT ' . implode(', ', $columns) . ' FROM ' . Schema::table($table)
            );
        }

        return self::shapeOf($tables);
    }

    /**
     * Den Abzug einspielen — **einmal, und nur in ein leeres Modell**.
     *
     * ⚠️ *Ein Bestand, der schon steht, bekommt nur die Fassungsnummer notiert: der Abzug ist der
     * Anfangszustand einer frischen Installation und geht an einem gewachsenen Baum vorbei.*
     *
     * @return array<string, int> Was je Tabelle geschrieben wurde; leer, wenn nichts zu tun war.
     */
    public function importOnce(): array
    {
        if ((int) get_option(self::OPTION, 0) >= self::VERSION) {
            return [];
        }

        if (! $this->modelIsEmpty()) {
            update_option(self::OPTION, self::VERSION, true);

            return [];
        }

        $written = $this->import();

        $this->sealIdSpace();

        update_option(self::OPTION, self::VERSION, true);

        return $written;
    }

    /**
     * Die Zeilen und die Optionen des Abzugs schreiben, ohne Rücksicht auf die Fassungsnummer.
     *
     * ⚠️ **Ohne {@see self::sealIdSpace()}, und das ist der Grund für die Trennung**: *ein
     * `ALTER TABLE … AUTO_INCREMENT` bestätigt in MySQL stillschweigend die laufende Umklammerung.
     * Ein Wächter, der den Abzug probeweise einspielt, darf ihn deshalb nicht mitrufen — sonst
     * schriebe er genau den Bestand fest, den `lib/no-write.php` zurückdrehen soll.*
     *
     * @return array<string, int>
     */
    public function import(): array
    {
        $image = self::read();

        if ($image === null) {
            return [];
        }

        global $wpdb;

        $written = [];

        foreach (self::TABLES as $table => $columns) {
            $rows = $image['tabellen'][$table] ?? [];
            $name = Schema::table($table);
            $done = 0;

            foreach ($rows as $row) {
                // ⚠️ *`NULL` steht als Literal in der Vorlage und nicht als Wert (`CD-6`): `%s`
                // macht aus `null` die leere Zeichenkette, und «leer» ist an `implemented_by` oder
                // `parent_node_id` etwas anderes als «nichts».*
                $slots  = [];
                $values = [];

                foreach ($columns as $column) {
                    $value = $row[$column] ?? null;

                    // ⚠️ *Ein Abzug von vor Fassung 46 kennt `klasse` nicht; die Spalte ist
                    // `NOT NULL`, und leer heisst «noch nicht vergeben» — {@see Schema::install()}
                    // holt das beim nächsten Fassungslauf nach ([D-716](../../../docs/NewConcept/90-decision-log.md)).*
                    if ($value === null && $column === 'klasse') {
                        $value = '';
                    }

                    // ⚠️ *Dasselbe für `read_only` (Fassung 48): ein älterer Abzug kennt die Spalte
                    // nicht, und «nichts gesagt» ist `0` — die Vorgabe der Spalte.*
                    if ($value === null && $column === 'read_only') {
                        $value = '0';
                    }

                    if ($value === null) {
                        $slots[] = 'NULL';

                        continue;
                    }

                    $slots[]  = '%s';
                    $values[] = (string) $value;
                }

                Query::run(
                    "{$table} aus dem Abzug schreiben",
                    $values === []
                        ? 'INSERT INTO ' . $name . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $slots) . ')'
                        : $wpdb->prepare(
                            'INSERT INTO ' . $name . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $slots) . ')',
                            $values
                        )
                );

                $done++;
            }

            $written[$table] = $done;
        }

        foreach (($image['optionen'] ?? []) as $option => $value) {
            update_option((string) $option, (string) $value, true);
        }

        return $written;
    }

    /**
     * Die Zähler hinter die eingespielten Nummern setzen.
     *
     * ⚠️ *Ohne das vergäbe die erste eigene Zeile eine Nummer, die der Abzug schon benutzt —
     * `AUTO_INCREMENT` steht auf einer frischen Installation bei `2` und weiss von den
     * eingefügten Ids nichts, weil `INSERT` mit ausdrücklicher Id ihn nur nach oben zieht, wenn
     * MySQL die Tabelle danach anfasst. Der Aufruf ist harmlos: einen zu kleinen Wert hebt MySQL
     * still auf das nötige Minimum.*
     */
    public function sealIdSpace(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            $name    = Schema::table($table);
            $highest = (int) (Query::value("höchste Id in {$table}", 'SELECT MAX(id) FROM ' . $name) ?? '0');

            Query::run(
                "Id-Raum von {$table} nachziehen",
                'ALTER TABLE ' . $name . ' AUTO_INCREMENT = ' . ($highest + 1)
            );
        }
    }

    /** Ob überhaupt noch kein Knoten steht — die Frage, die «frische Installation» beantwortet. */
    public function modelIsEmpty(): bool
    {
        return (int) (Query::value('Knoten zählen', 'SELECT COUNT(*) FROM ' . Schema::table('nodes')) ?? '0') === 0;
    }

    /**
     * Ob die feste Spaltenliste die Tabelle noch vollständig beschreibt.
     *
     * @return list<string> Die Spalten, die in der Datenbank stehen und im Abzug fehlen.
     */
    public static function columnsAreComplete(string $table): array
    {
        global $wpdb;

        $live = Query::column(
            "Spalten von {$table} lesen",
            $wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
                Schema::table($table)
            )
        );

        return array_values(array_diff($live, self::TABLES[$table]));
    }
}
