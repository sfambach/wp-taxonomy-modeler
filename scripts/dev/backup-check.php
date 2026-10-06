<?php declare(strict_types=1);
/**
 * Die Sicherung trägt den ganzen Stand, und eine Sicherung, die nicht passt, wird abgewiesen, bevor etwas fällt (D-908).
 *
 *     php scripts/dev/backup-check.php [path/to/wordpress]
 *
 * ```mermaid
 * flowchart LR
 *   E["export"] --> Z["ZIP"] --> P["Zeilen, Bauanleitungen, Dateien gezählt"]
 *   Z --> F["Fassung zu neu · Bauanleitung falsch"] --> A["abgewiesen, nichts geschrieben"]
 * ```
 *
 * ⚠️ **Er spielt nichts ein.** *Einspielen wirft alle Tabellen weg — `DROP` schliesst jede Klammer ab, und kein
 * Wächter darf das Modell des Eigentümers ersetzen. Der Rundweg mit Medienumzug ist von Hand an zwei Websites
 * gemessen (D-908); hier steht, was sich ohne Schreiben sagen lässt.*
 *
 * @see docs/NewConcept/90-decision-log.md
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: null;

if ($root === null) {
    $dir = getcwd();
    while ($dir !== '' && ! is_readable($dir . '/wp-load.php')) {
        $up  = dirname($dir);
        $dir = $up === $dir ? '' : $up;
    }
    $root = $dir;
}

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php. Pass the WordPress folder as the first argument.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';

// ⚠️ **Kein Wächter schreibt in das Modell des Eigentümers** — siehe `lib/no-write.php`.
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Type\MediaType;
use Taxmod\WordPress\Persistence\Backup;
use Taxmod\WordPress\Persistence\Query;
use Taxmod\WordPress\Persistence\Schema;

global $wpdb;
$ok  = 0;
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $ok, $bad;

    if ($passed) {
        $ok++;
        echo "  OK   $what\n";

        return;
    }

    $bad++;
    echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n";
}

/** Eine Sicherung mit verändertem Manifest — der Rest bleibt, wie er ist. */
function variant(string $from, callable $change): string
{
    $to = wp_tempnam('taxmod-backup-variant');
    copy($from, $to);
    $zip      = new ZipArchive();
    $zip->open($to);
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    $zip->addFromString('manifest.json', (string) wp_json_encode($change($manifest)));
    $zip->close();

    return $to;
}

require_once ABSPATH . 'wp-admin/includes/file.php';

echo "Sicherung\n";

$file   = wp_tempnam('taxmod-backup-check');
$result = (new Backup())->export($file);
$zip    = new ZipArchive();

check('die Sicherung ist ein lesbares ZIP', $zip->open($file) === true);

$manifest = json_decode((string) $zip->getFromName('manifest.json'), true);

check('das Manifest nennt die gespeicherte Schemafassung', ($manifest['schema'] ?? null) === (int) get_option(Schema::VERSION_OPTION, 0));
check('jede Tabelle des Codes ist dabei', array_diff(Schema::tableNames(), array_keys($manifest['tables'] ?? [])) === []);

foreach ($manifest['tables'] as $table => $count) {
    $rows  = (int) Query::value("{$table} zählen", 'SELECT COUNT(*) FROM ' . Schema::table($table));
    $lines = substr_count((string) $zip->getFromName('tables/' . $table . '.ndjson'), "\n");

    check("{$table}: {$count} Zeilen im Manifest, in der Datei und in der Tabelle", $count === $rows && $lines === $rows, "Tabelle {$rows}, Datei {$lines}");
    check("{$table}: Bauanleitung ohne Präfix der Website", str_starts_with((string) ($manifest['ddl'][$table] ?? ''), 'CREATE TABLE `{taxmod_prefix}' . $table . '`') && ! str_contains((string) $manifest['ddl'][$table], $wpdb->prefix . 'taxmod_'));
}

$referenced = [];

foreach (['relation_records', 'relation_records_history'] as $table) {
    foreach (Query::column('Verweise', $wpdb->prepare('SELECT DISTINCT value_text FROM ' . Schema::table($table) . ' WHERE value_text LIKE %s', 'media:%')) as $value) {
        $id = MediaType::libraryIdOf((string) $value);
        if ($id !== null) {
            $referenced[$id] = true;
        }
    }
}

$carried = array_column($manifest['media'], 'id');
$inZip   = array_filter($manifest['media'], static fn (array $m): bool => $zip->locateName($m['entry']) !== false && sha1((string) $zip->getFromName($m['entry'])) === $m['sha1']);

check('jede verwiesene Datei ist dabei oder als fehlend gemeldet', count($carried) + count($result['media_missing']) === count($referenced), count($carried) . ' + ' . count($result['media_missing']) . ' gegen ' . count($referenced));
check('jede mitgenommene Datei liegt im ZIP mit ihrem Fingerabdruck', count($inZip) === count($carried));
check('keine Option des Betreibers in der Sicherung', ! isset($manifest['options']['taxmod_schema_version']));

$zip->close();

echo "Abweisen, bevor etwas fällt\n";

$nodesBefore = (int) Query::value('Knoten zählen', 'SELECT COUNT(*) FROM ' . Schema::table('nodes'));

foreach ([
    'eine neuere Schemafassung' => static fn (array $m): array => ['schema' => Schema::VERSION + 1] + $m,
    'eine Bauanleitung für eine andere Tabelle' => static function (array $m): array {
        $m['ddl']['nodes'] = 'CREATE TABLE `{taxmod_prefix}fremd` (id int)';

        return $m;
    },
    'ein Tabellenname mit Sonderzeichen' => static function (array $m): array {
        $m['tables']['x`; DROP TABLE y'] = 0;

        return $m;
    },
] as $what => $change) {
    $bent = variant($file, $change);

    try {
        (new Backup())->restore($bent);
        check("{$what} wird abgewiesen", false, 'eingespielt');
    } catch (RuntimeException $e) {
        check("{$what} wird abgewiesen", true);
    }

    @unlink($bent);
}

check('das Modell ist unberührt', (int) Query::value('Knoten zählen', 'SELECT COUNT(*) FROM ' . Schema::table('nodes')) === $nodesBefore);

@unlink($file);

echo "\n{$ok} OK, {$bad} FAIL\n";
exit($bad === 0 ? 0 : 1);
