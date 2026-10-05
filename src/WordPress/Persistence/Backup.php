<?php declare(strict_types=1);

namespace Taxmod\WordPress\Persistence;

use Taxmod\Core\Model\Type\MediaType;
use Taxmod\Core\Service\BackupRewrite;

/**
 * Sichert das ganze Modell samt seinen Mediathek-Dateien in ein ZIP und spielt es wieder ein ([D-908](../../../docs/NewConcept/90-decision-log.md)).
 *
 * ```mermaid
 * flowchart LR
 *   T[(taxmod-Tabellen)] --> Z["ZIP · manifest · tables/*.ndjson · media/"]
 *   O[(taxmod-Optionen)] --> Z
 *   M[(Mediathek)] --> Z
 *   Z -->|"Dateien zuerst"| N["neue Mediathek-Ids"]
 *   N --> W["Tabellen aus ihrer Bauanleitung neu anlegen · füllen · media:-Ids und Adresse umschreiben"]
 * ```
 *
 * ⚠️ **Ersetzen und nicht ergänzen.** *Eine Sicherung ist ein ganzer Stand: Nummern, Zeiger in den Optionen und
 * Geschichte gehören zusammen. Ein Mischbestand aus zwei Ständen hätte Zeiger ins Leere — darum wird alles, was
 * dem Modell gehört, in einem Zug gelöscht und aus der Sicherung neu geschrieben.*
 *
 * ⚠️ **Die Tabellen kommen mit ihrer Bauanleitung.** *Die Sicherung trägt zu jeder Tabelle ihr `CREATE TABLE`. Das
 * Einspielen wirft alle Tabellen des Modells weg und legt sie daraus neu an — so passt es auf jede Website, auch auf
 * eine, deren Umbau auf die neue Fassung mittendrin stehen blieb. Danach steht die Schemafassung der Sicherung; ist
 * sie älter als der Code, holt der nächste Seitenaufruf den Umbau nach. Eine **neuere** wird abgewiesen.*
 *
 * ⚠️ *Das Änderungsprotokoll wird mitgesichert, aber nicht umgeschrieben — es ist Geschichte, und eine `media:`-Id
 * darin nennt die Datei, wie sie damals hiess.*
 *
 * @see docs/NewConcept/90-decision-log.md
 */
final class Backup
{
    public const FORMAT = 1;

    /** Die Zeilen je `INSERT` — gross genug gegen tausend Rundreisen, klein genug für `max_allowed_packet`. */
    private const BATCH = 200;

    /** Die Spalten, in denen ein Wert eine Mediathek-Id oder eine Adresse tragen kann. */
    private const REWRITTEN = [
        'relation_records'         => 'value_text',
        'relation_records_history' => 'value_text',
    ];

    /**
     * Optionen eines Betreibers und nicht des Modells — sie bleiben auf der Zielseite, wie sie sind.
     *
     * ⚠️ *Dieselben Gründe wie in {@see SeedImage::OPTIONS_OUT}: die Schemafassung gehört dem Schema, die übrigen
     * sind Bildschirmeinstellungen dessen, der die Website betreibt.*
     */
    private const OPTIONS_KEPT = [
        'taxmod_schema_version',
        'taxmod_developer',
        'taxmod_developer_mode',
        'taxmod_icon_size',
        'taxmod_font_size',
        'taxmod_show_trash',
    ];

    /** Der SHA-1 einer eingespielten Datei an ihrem Mediathek-Eintrag — damit ein zweites Einspielen sie wiederfindet. */
    private const FINGERPRINT = '_taxmod_backup_sha1';

    /** Steht in jeder Bauanleitung, wo der Tabellenname der Zielseite hingehört. */
    private const PREFIX_SLOT = '{taxmod_prefix}';

    /** Der Ordner für die Sicherung, die jedes Einspielen vorher vom bestehenden Stand anlegt. */
    private const SAFETY_DIR = 'taxmod-backups';

    /**
     * Den ganzen Stand in eine ZIP-Datei schreiben.
     *
     * @return array{file: string, media_missing: list<int>} Pfad der Datei und Ids, deren Datei fehlt.
     */
    public function export(string $file): array
    {
        self::zipIsAvailable();

        $zip = new \ZipArchive();

        if ($zip->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Die Sicherungsdatei lässt sich nicht anlegen: ' . $file);
        }

        $counts = [];
        $ddl    = [];
        $temp   = [];

        // ⚠️ *Was dasteht, nicht was der Code erwartet: auch eine halb umgebaute Website lässt sich so sichern.*
        foreach ($this->existingTables() as $table) {
            [$path, $counts[$table]] = $this->tableToFile($table);
            $ddl[$table]             = $this->createStatement($table);
            $temp[]                  = $path;
            $zip->addFile($path, 'tables/' . $table . '.ndjson');
        }

        [$media, $missing] = $this->collectMedia($zip);

        $zip->addFromString('manifest.json', (string) wp_json_encode([
            'format'      => self::FORMAT,
            'plugin'      => \Taxmod\WordPress\Plugin::VERSION,
            'schema'      => (int) get_option(Schema::VERSION_OPTION, 0),
            'site_url'    => home_url(),
            'created_at'  => gmdate('c'),
            'tables'      => $counts,
            'ddl'         => $ddl,
            'options'     => $this->options(),
            'media'       => $media,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        if (! $zip->close()) {
            throw new \RuntimeException('Die Sicherungsdatei liess sich nicht abschliessen.');
        }

        foreach ($temp as $path) {
            @unlink($path);
        }

        return ['file' => $file, 'media_missing' => $missing];
    }

    /**
     * Eine Sicherung einspielen: vorher den bestehenden Stand sichern, dann Dateien, dann Tabellen und Optionen.
     *
     * @return array{tables: array<string, int>, media_new: int, media_reused: int, media_missing: int, safety: string}
     */
    public function restore(string $file): array
    {
        self::zipIsAvailable();

        $zip = new \ZipArchive();

        if ($zip->open($file) !== true) {
            throw new \RuntimeException('Die Datei ist kein lesbares ZIP.');
        }

        try {
            $manifest = $this->manifest($zip);
            $safety   = $this->safetyCopy();
            $media    = $this->restoreMedia($zip, $manifest['media']);
            $rewrite  = new BackupRewrite($media['map'], (string) $manifest['site_url'], home_url());
            $tables   = $this->restoreTables($zip, $manifest, $rewrite, $safety);
        } finally {
            $zip->close();
        }

        $this->restoreOptions($manifest['options']);
        update_option(Schema::VERSION_OPTION, $manifest['schema'], true);

        if ($manifest['schema'] === Schema::VERSION) {
            Schema::buildTheReadableViews();
        }

        wp_cache_flush();
        Query::forget();

        return [
            'tables'        => $tables,
            'media_new'     => $media['new'],
            'media_reused'  => $media['reused'],
            'media_missing' => $media['missing'],
            'safety'        => $safety,
        ];
    }

    private static function zipIsAvailable(): void
    {
        // *`wp_tempnam()` und `wp_upload_bits()` stehen ausserhalb der Verwaltung nicht bereit — etwa in der Kommandozeile.*
        require_once ABSPATH . 'wp-admin/includes/file.php';

        if (! class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('PHP hat hier keine ZIP-Erweiterung (ZipArchive); ohne sie geht keine Sicherung.');
        }
    }

    /**
     * Die Tabellen des Modells, die in der Datenbank stehen — ohne Präfix, ohne Sichten.
     *
     * @return list<string>
     */
    private function existingTables(): array
    {
        global $wpdb;

        $prefix = $wpdb->prefix . 'taxmod_';
        $names  = Query::column(
            'Tabellen des Modells auflisten',
            $wpdb->prepare(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME",
                $wpdb->esc_like($prefix) . '%'
            )
        );

        return array_values(array_map(static fn ($name): string => substr((string) $name, strlen($prefix)), $names));
    }

    /** Das `CREATE TABLE` einer Tabelle, mit einem Platzhalter statt des Präfixes — die Zielseite hat vielleicht ein anderes. */
    private function createStatement(string $table): string
    {
        global $wpdb;

        $row = $wpdb->get_row('SHOW CREATE TABLE ' . Schema::table($table), ARRAY_N);

        if (! is_array($row) || ! isset($row[1])) {
            throw new \RuntimeException('Die Bauanleitung von ' . $table . ' lässt sich nicht lesen.');
        }

        return str_replace($wpdb->prefix . 'taxmod_', self::PREFIX_SLOT, (string) $row[1]);
    }

    /**
     * Eine Tabelle zeilenweise als NDJSON in eine Zwischendatei — in Blöcken nach Id, nie die ganze Tabelle im Speicher.
     *
     * @return array{0: string, 1: int}
     */
    private function tableToFile(string $table): array
    {
        global $wpdb;

        $path = wp_tempnam('taxmod-' . $table);
        $out  = fopen($path, 'wb');

        if ($out === false) {
            throw new \RuntimeException('Zwischendatei für ' . $table . ' lässt sich nicht schreiben.');
        }

        $name   = Schema::table($table);
        $order  = implode(', ', array_map(static fn (string $c): string => '`' . sanitize_key($c) . '`', $this->primaryKey($table)));
        $offset = 0;
        $count  = 0;

        // ⚠️ *Nach Seiten über den ganzen Primärschlüssel und nicht über `id`: die Schatten tragen `(id, version)`,
        // dieselbe Id steht dort mehrmals.*
        do {
            $rows = Query::rows(
                "{$table} sichern",
                $wpdb->prepare('SELECT * FROM ' . $name . ' ORDER BY ' . $order . ' LIMIT %d, 2000', $offset)
            );

            foreach ($rows as $row) {
                fwrite($out, (string) wp_json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
                $count++;
            }

            $offset += 2000;
        } while (count($rows) === 2000);

        fclose($out);

        return [$path, $count];
    }

    /** @return list<string> */
    private function primaryKey(string $table): array
    {
        global $wpdb;

        $columns = Query::column(
            "Primärschlüssel von {$table}",
            $wpdb->prepare(
                "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND CONSTRAINT_NAME = 'PRIMARY'
                 ORDER BY ORDINAL_POSITION",
                Schema::table($table)
            )
        );

        if ($columns !== []) {
            return array_map('strval', $columns);
        }

        // *Ohne Primärschlüssel (eine Tabelle alter Fassung) die erste Spalte — Hauptsache, die Seiten sind stabil.*
        $first = Query::value(
            "erste Spalte von {$table}",
            $wpdb->prepare(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION LIMIT 1',
                Schema::table($table)
            )
        );

        return [(string) $first];
    }

    /** @return array<string, string> Alle Optionen des Modells, roh wie in der Datenbank — also ggf. serialisiert. */
    private function options(): array
    {
        global $wpdb;

        $rows = Query::rows(
            'Optionen sichern',
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name",
                $wpdb->esc_like('taxmod_') . '%'
            )
        );

        $options = [];

        foreach ($rows as $row) {
            if (! in_array($row['option_name'], self::OPTIONS_KEPT, true)) {
                $options[(string) $row['option_name']] = (string) $row['option_value'];
            }
        }

        return $options;
    }

    /**
     * Jede Datei, auf die ein Wert mit `media:<Id>` zeigt, samt ihrer Beschreibung.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<int>}
     */
    private function collectMedia(\ZipArchive $zip): array
    {
        global $wpdb;

        $ids = [];

        foreach (self::REWRITTEN as $table => $column) {
            if (! $this->hasColumn($table, $column)) {
                continue;
            }

            $values = Query::column(
                'Mediathek-Verweise sammeln',
                $wpdb->prepare(
                    'SELECT DISTINCT value_text FROM ' . Schema::table($table) . ' WHERE value_text LIKE %s',
                    $wpdb->esc_like(MediaType::LIBRARY_SCHEME) . '%'
                )
            );

            foreach ($values as $value) {
                $id = MediaType::libraryIdOf((string) $value);

                if ($id !== null) {
                    $ids[$id] = true;
                }
            }
        }

        $ids = array_keys($ids);
        sort($ids);

        $media   = [];
        $missing = [];

        foreach ($ids as $id) {
            $path = get_attached_file($id);
            $post = get_post($id);

            if ($path === false || ! is_readable($path) || ! $post instanceof \WP_Post) {
                $missing[] = $id;

                continue;
            }

            $entry = 'media/' . $id . '/' . wp_basename($path);
            $zip->addFile($path, $entry);

            $media[] = [
                'id'          => $id,
                'entry'       => $entry,
                'relative'    => (string) get_post_meta($id, '_wp_attached_file', true),
                'sha1'        => (string) sha1_file($path),
                'mime'        => $post->post_mime_type,
                'title'       => $post->post_title,
                'caption'     => $post->post_excerpt,
                'description' => $post->post_content,
                'alt'         => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            ];
        }

        return [$media, $missing];
    }

    private function hasColumn(string $table, string $column): bool
    {
        global $wpdb;

        return Query::value(
            "Spalte {$column} in {$table}",
            $wpdb->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                Schema::table($table),
                $column
            )
        ) === '1';
    }

    /** @return array{schema: int, site_url: string, tables: array<string, int>, ddl: array<string, string>, options: array<string, string>, media: list<array<string, mixed>>} */
    private function manifest(\ZipArchive $zip): array
    {
        $raw = $zip->getFromName('manifest.json');

        if ($raw === false) {
            throw new \RuntimeException('Das ZIP ist keine Sicherung des Taxonomy Modellers (manifest.json fehlt).');
        }

        $manifest = json_decode($raw, true);

        if (! is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
            throw new \RuntimeException('Die Sicherung hat ein unbekanntes Format.');
        }

        $schema = (int) ($manifest['schema'] ?? 0);

        if ($schema < 1 || $schema > Schema::VERSION) {
            throw new \RuntimeException(sprintf(
                'Die Sicherung stammt aus Schemafassung %d, dieses Plugin kennt höchstens %d. Erst das Plugin aktualisieren.',
                $schema,
                Schema::VERSION
            ));
        }

        $tables = array_map('intval', (array) ($manifest['tables'] ?? []));
        $ddl    = array_map('strval', (array) ($manifest['ddl'] ?? []));

        foreach (array_keys($tables) as $table) {
            if (preg_match('/^[a-z0-9_]+$/', (string) $table) !== 1 || ! isset($ddl[$table])) {
                throw new \RuntimeException('Die Sicherung nennt die Tabelle «' . $table . '» ohne gültige Bauanleitung.');
            }

            if (preg_match('/^CREATE TABLE `' . preg_quote(self::PREFIX_SLOT, '/') . preg_quote((string) $table, '/') . '` \(/', $ddl[$table]) !== 1) {
                throw new \RuntimeException('Die Bauanleitung von ' . $table . ' ist kein CREATE TABLE dieser Tabelle.');
            }
        }

        return [
            'schema'   => $schema,
            'site_url' => (string) ($manifest['site_url'] ?? ''),
            'tables'   => $tables,
            'ddl'      => $ddl,
            'options'  => array_map('strval', (array) ($manifest['options'] ?? [])),
            'media'    => array_values((array) ($manifest['media'] ?? [])),
        ];
    }

    /**
     * Den bestehenden Stand sichern, bevor er ersetzt wird — in einen Ordner, den der Webserver nicht ausliefert.
     *
     * ⚠️ *Der Name trägt einen Zufallsteil, und `.htaccess` plus `index.php` sperren den Ordner: er liegt unter
     * `uploads`, weil nur dort sicher geschrieben werden darf.*
     */
    private function safetyCopy(): string
    {
        $uploads = wp_upload_dir();
        $dir     = trailingslashit($uploads['basedir']) . self::SAFETY_DIR;

        if (! wp_mkdir_p($dir)) {
            throw new \RuntimeException('Der Ordner für die Sicherheitskopie lässt sich nicht anlegen: ' . $dir);
        }

        if (! file_exists($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
            file_put_contents($dir . '/index.php', "<?php\n");
        }

        $file = $dir . '/vor-einspielen-' . gmdate('Ymd-His') . '-' . wp_generate_password(12, false) . '.zip';

        $this->export($file);

        return $file;
    }

    /**
     * Die Dateien in die Mediathek bringen und die alte Id auf die neue abbilden.
     *
     * ⚠️ **Gleiche Datei, gleiche Id.** *Liegt am selben Pfad schon eine Datei mit demselben Inhalt (SHA-1), wird
     * sie wiederverwendet — eine Website, die aus einer Kopie der anderen entstand, bekommt so keine Doppel.*
     *
     * @param list<array<string, mixed>> $entries
     * @return array{map: array<int, int>, new: int, reused: int, missing: int}
     */
    private function restoreMedia(\ZipArchive $zip, array $entries): array
    {
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $map     = [];
        $new     = 0;
        $reused  = 0;
        $missing = 0;

        foreach ($entries as $entry) {
            $oldId    = (int) ($entry['id'] ?? 0);
            $relative = (string) ($entry['relative'] ?? '');
            $existing = $relative === '' ? 0 : $this->attachmentAt($relative, (string) ($entry['sha1'] ?? ''));

            if ($existing > 0) {
                $map[$oldId] = $existing;
                $reused++;

                continue;
            }

            $contents = $zip->getFromName((string) ($entry['entry'] ?? ''));

            if ($contents === false) {
                $missing++;

                continue;
            }

            $map[$oldId] = $this->insertAttachment($entry, $contents);
            $new++;
        }

        return ['map' => $map, 'new' => $new, 'reused' => $reused, 'missing' => $missing];
    }

    private function attachmentAt(string $relative, string $sha1): int
    {
        global $wpdb;

        // ⚠️ *Auch nach dem Fingerabdruck, den ein früheres Einspielen hinterliess: hatte WordPress die Datei damals
        // umbenannt (`-1`), stimmt der Pfad nicht mehr, und ein zweites Einspielen legte sonst ein Doppel an.*
        $ids = Query::column(
            'Mediathek nach derselben Datei fragen',
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE (meta_key = '_wp_attached_file' AND meta_value = %s) OR (meta_key = %s AND meta_value = %s)",
                $relative,
                self::FINGERPRINT,
                $sha1
            )
        );

        foreach ($ids as $id) {
            $path = get_attached_file((int) $id);

            if ($path !== false && is_readable($path) && sha1_file($path) === $sha1) {
                return (int) $id;
            }
        }

        return 0;
    }

    /** @param array<string, mixed> $entry */
    private function insertAttachment(array $entry, string $contents): int
    {
        $relative = (string) ($entry['relative'] ?? '');
        $month    = preg_match('#^(\d{4}/\d{2})/#', $relative, $found) === 1 ? $found[1] : null;
        $upload   = wp_upload_bits(wp_basename((string) $entry['entry']), null, $contents, $month);

        if (! empty($upload['error'])) {
            throw new \RuntimeException('Datei ' . $entry['entry'] . ' liess sich nicht hochladen: ' . $upload['error']);
        }

        $id = wp_insert_attachment([
            'post_mime_type' => (string) ($entry['mime'] ?? ''),
            'post_title'     => (string) ($entry['title'] ?? ''),
            'post_excerpt'   => (string) ($entry['caption'] ?? ''),
            'post_content'   => (string) ($entry['description'] ?? ''),
            'post_status'    => 'inherit',
        ], $upload['file'], 0, true);

        if (is_wp_error($id)) {
            throw new \RuntimeException('Mediathek-Eintrag für ' . $entry['entry'] . ' liess sich nicht anlegen: ' . $id->get_error_message());
        }

        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));

        update_post_meta($id, self::FINGERPRINT, (string) ($entry['sha1'] ?? ''));

        if ((string) ($entry['alt'] ?? '') !== '') {
            update_post_meta($id, '_wp_attachment_image_alt', (string) $entry['alt']);
        }

        return (int) $id;
    }

    /**
     * Alle Tabellen und Sichten des Modells wegwerfen, aus den Bauanleitungen neu anlegen und füllen.
     *
     * ⚠️ **Keine Transaktion, und das ist kein Versehen.** *`DROP` und `CREATE` schliessen in MySQL jede offene
     * Transaktion ab; ein `ROLLBACK` versprach hier nur, was er nicht hält. Der Rückweg ist die Sicherheitskopie,
     * die vorher geschrieben wurde — die Fehlermeldung nennt sie.*
     *
     * @param array{tables: array<string, int>, ddl: array<string, string>} $manifest
     * @return array<string, int>
     */
    private function restoreTables(\ZipArchive $zip, array $manifest, BackupRewrite $rewrite, string $safety): array
    {
        global $wpdb;

        $written = [];

        Query::run('Fremdschlüssel für das Einspielen aussetzen', 'SET FOREIGN_KEY_CHECKS = 0');

        try {
            $this->dropEverything();

            foreach ($manifest['ddl'] as $table => $create) {
                Query::run("{$table} anlegen", $this->forThisServer(str_replace(self::PREFIX_SLOT, $wpdb->prefix . 'taxmod_', $create)));
            }

            foreach (array_keys($manifest['tables']) as $table) {
                $written[$table] = $this->restoreTable($zip, $table, $rewrite);

                if ($written[$table] !== $manifest['tables'][$table]) {
                    throw new \RuntimeException(sprintf(
                        '%s: %d Zeilen gelesen, die Sicherung nennt %d. Die Datei ist unvollständig.',
                        $table,
                        $written[$table],
                        $manifest['tables'][$table]
                    ));
                }
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException($e->getMessage() . ' — Der vorige Stand liegt in ' . $safety, 0, $e);
        } finally {
            $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $written;
    }

    /** Jede Tabelle und Sicht mit dem Präfix des Modells — auch Reste älterer Fassungen, die der Code nicht mehr kennt. */
    private function dropEverything(): void
    {
        global $wpdb;

        $objects = Query::rows(
            'Tabellen und Sichten des Modells finden',
            $wpdb->prepare(
                'SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s',
                $wpdb->esc_like($wpdb->prefix . 'taxmod_') . '%'
            )
        );

        foreach ($objects as $object) {
            $name = '`' . str_replace('`', '', (string) $object['TABLE_NAME']) . '`';
            Query::run(
                'Altbestand wegwerfen',
                ($object['TABLE_TYPE'] === 'VIEW' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . $name
            );
        }
    }

    /**
     * ⚠️ *Eine Sicherung aus MySQL 8 kann die Sortierung `utf8mb4_0900_ai_ci` tragen, die MySQL 5.7 und MariaDB nicht
     * kennen — dort wird sie durch die nächstverwandte ersetzt.*
     */
    private function forThisServer(string $create): string
    {
        global $wpdb;

        $mariaDb = str_contains((string) $wpdb->db_server_info(), 'MariaDB');

        if (! $mariaDb && version_compare((string) $wpdb->db_version(), '8.0', '>=')) {
            return $create;
        }

        return str_replace('utf8mb4_0900_ai_ci', 'utf8mb4_unicode_520_ci', $create);
    }

    private function restoreTable(\ZipArchive $zip, string $table, BackupRewrite $rewrite): int
    {
        $stream = $zip->getStream('tables/' . $table . '.ndjson');

        if ($stream === false) {
            throw new \RuntimeException('In der Sicherung fehlt die Tabelle ' . $table . '.');
        }

        $column = self::REWRITTEN[$table] ?? null;
        $batch  = [];
        $count  = 0;

        while (($line = fgets($stream)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

            if ($column !== null && array_key_exists($column, $row)) {
                $row[$column] = $rewrite->text($row[$column] === null ? null : (string) $row[$column]);
            }

            $batch[] = $row;
            $count++;

            if (count($batch) === self::BATCH) {
                $this->insertRows($table, $batch);
                $batch = [];
            }
        }

        fclose($stream);

        if ($batch !== []) {
            $this->insertRows($table, $batch);
        }

        return $count;
    }

    /**
     * Mehrere Zeilen in einem `INSERT`.
     *
     * ⚠️ *`NULL` steht als Literal in der Vorlage und nicht als Wert — `%s` machte aus `null` die leere
     * Zeichenkette, und «leer» ist etwas anderes als «nichts» (derselbe Grund wie in {@see SeedImage::import()}).*
     *
     * @param non-empty-list<array<string, mixed>> $rows
     */
    private function insertRows(string $table, array $rows): void
    {
        global $wpdb;

        $columns = array_keys($rows[0]);
        $tuples  = [];
        $values  = [];

        foreach ($rows as $row) {
            $slots = [];

            foreach ($columns as $column) {
                $value = $row[$column] ?? null;

                if ($value === null) {
                    $slots[] = 'NULL';

                    continue;
                }

                $slots[]  = '%s';
                $values[] = (string) $value;
            }

            $tuples[] = '(' . implode(', ', $slots) . ')';
        }

        $sql = 'INSERT INTO ' . Schema::table($table) . ' (`' . implode('`, `', array_map('sanitize_key', $columns)) . '`) VALUES ' . implode(', ', $tuples);

        Query::run("{$table} aus der Sicherung schreiben", $values === [] ? $sql : $wpdb->prepare($sql, $values));
    }

    /**
     * Die Optionen des Modells ersetzen — roh geschrieben, weil sie roh gesichert wurden.
     *
     * ⚠️ *`update_option()` würde einen schon serialisierten Wert ein zweites Mal serialisieren.*
     *
     * @param array<string, string> $options
     */
    private function restoreOptions(array $options): void
    {
        global $wpdb;

        $kept = implode(', ', array_fill(0, count(self::OPTIONS_KEPT), '%s'));

        Query::run(
            'Optionen des Modells entfernen',
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT IN ({$kept})",
                $wpdb->esc_like('taxmod_') . '%',
                ...self::OPTIONS_KEPT
            )
        );

        foreach ($options as $name => $value) {
            if (! str_starts_with($name, 'taxmod_') || in_array($name, self::OPTIONS_KEPT, true)) {
                continue;
            }

            Query::run(
                "Option {$name} schreiben",
                $wpdb->prepare(
                    "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'on')",
                    $name,
                    $value
                )
            );
        }

        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }
}
