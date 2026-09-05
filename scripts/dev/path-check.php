<?php declare(strict_types=1);
/**
 * `path` faellt — und kommt nicht zurueck.
 *
 *     php scripts/dev/path-check.php [path/to/wordpress]
 *
 * ⚠️ **Hier stand bis zum 2026-09-05 ein anderer Waechter gleichen Namens.** *Er pruefte
 * `settings.path` — die Adresse, die eine Einstellung brauchte, um zu sagen, **welche** Stelle sie
 * beantwortet ([D-413](../../docs/NewConcept/90-decision-log.md)). **Die Tabelle ist mit
 * [D-579](../../docs/NewConcept/90-decision-log.md) gestrichen**, und mit ihr die Zusage; der
 * Waechter fiel damals mit ihr, ohne dass etwas an seine Stelle trat. Dies ist die neue Zusage,
 * und sie ist die umgekehrte: **die Spalte ist weg und darf nicht wiederkommen.**
 *
 * Die drei Aufgaben, die sie abraeumen — [`tasks.md`](../../docs/pakete/modelltabellen/tasks.md):
 *
 * | | Tabelle | Was `path` dort war |
 * |---|---|---|
 * | **TASK-003** | `labels`, `settings` | eine Adresse **innerhalb** eines Eigentuemers — nachweislich nie belegt |
 * | **TASK-002** | `relation_records` | ein reiner Spiegel von `relation_id` |
 * | **TASK-001** | `nodes` | der Vorfahrenweg, den seit TASK-018 `parent_node_id` traegt |
 *
 * ⚠️ **Warum ein Waechter und nicht nur ein `ALTER TABLE`:** *`dbDelta` **fuegt fehlende Spalten
 * hinzu**. Steht `path` versehentlich wieder in einer `CREATE TABLE`-Anweisung, legt die naechste
 * Aktivierung sie klaglos wieder an — leer, ungelesen und ohne dass irgendetwas rot wird. **Genau
 * so ist eine gestrichene Spalte schon einmal zurueckgekommen.***
 *
 * ⚠️ *Dieser Lauf schreibt nichts und legt nichts an — er liest nur, also gibt es nichts wegzuraeumen.*
 *
 * @see docs/pakete/modelltabellen/tasks.md
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
require dirname(__DIR__, 2) . '/vendor/autoload.php';

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

function hatSpalte(string $tabelle, string $spalte): bool
{
    global $wpdb;

    return $wpdb->get_var("SHOW COLUMNS FROM {$tabelle} LIKE '{$spalte}'") !== null;
}

function tabelleDa(string $tabelle): bool
{
    global $wpdb;

    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tabelle)) === $tabelle;
}

/**
 * Die Quelltexte, in denen eine Spalte genannt sein koennte.
 *
 * @return list<string>
 */
function quelltexte(): array
{
    $wurzel   = dirname(__DIR__, 2) . '/src';
    $gefunden = [];

    $lauf = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS));

    foreach ($lauf as $datei) {
        if ($datei->isFile() && $datei->getExtension() === 'php') {
            $gefunden[] = $datei->getPathname();
        }
    }

    sort($gefunden);

    return $gefunden;
}

/**
 * Eine Datei ohne ihre Kommentare.
 *
 * ⚠️ **Der Unterschied ist hier die ganze Pruefung.** *Eine gefallene Spalte hinterlaesst genau
 * einen Satz darueber, **warum** sie fiel — der Kommentar ist der Beleg und nicht der Verstoss.
 * Wer den Quelltext roh durchsucht, wird von seiner eigenen Begruendung rot.*
 */
function ohneKommentare(string $datei): string
{
    $reste = [];

    foreach (token_get_all((string) file_get_contents($datei)) as $stueck) {
        if (is_array($stueck) && in_array($stueck[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $reste[] = is_array($stueck) ? $stueck[1] : $stueck;
    }

    return implode('', $reste);
}

echo "1 · TASK-003 · labels und settings tragen keinen Pfad mehr\n";

$labels     = Schema::table('labels');
$labelTexte = Schema::table('label_texts');
$settings   = Schema::table('settings');

check('labels hat keine Spalte path', tabelleDa($labels) && ! hatSpalte($labels, 'path'));
check('label_texts hat keine Spalte path', tabelleDa($labelTexte) && ! hatSpalte($labelTexte, 'path'));

// ⚠️ *Die Tabelle ist mit D-579 gestrichen; steht sie noch da, ist die Fassung nicht gelaufen —
// und dann ist auch ihre `path`-Spalte wieder eine offene Frage.*
check('settings gibt es nicht mehr', ! tabelleDa($settings));

// ⚠️ **Die Zusage gilt auch der `CREATE TABLE`-Anweisung und nicht nur der Datenbank.** *`dbDelta`
// legt an, was in ihr steht; eine Spalte, die dort wieder auftaucht, ist beim naechsten Aufstieg
// zurueck, ohne dass jemand sie geschrieben haette.*
$anweisungen = [];

foreach ((new ReflectionMethod(Schema::class, 'statements'))->invoke(null) as $sql) {
    if (preg_match('/CREATE TABLE (\S+)/', (string) $sql, $treffer) === 1) {
        $anweisungen[$treffer[1]] = (string) $sql;
    }
}

function anweisungOhnePfad(array $anweisungen, string $tabelle): bool
{
    $sql = $anweisungen[$tabelle] ?? null;

    if ($sql === null) {
        return true;
    }

    return preg_match('/^\s*path\s/m', $sql) !== 1;
}

check('die Anweisung fuer labels nennt keinen Pfad', anweisungOhnePfad($anweisungen, $labels));
check('die Anweisung fuer label_texts nennt keinen Pfad', anweisungOhnePfad($anweisungen, $labelTexte));

// ⚠️ **Der Kern darf die Spalte nicht mehr kennen.** *Gesucht wird der Zugriff, nicht das Wort:
// `$label->path`, `'path' =>` an einer Beschriftungszeile, `labels.path`. Ein Fliesstext, der die
// gefallene Spalte **erklaert**, ist kein Verstoss — er ist der Grund, aus dem sie fiel.*
$treffer = [];

foreach (quelltexte() as $datei) {
    if (preg_match('/\blabels?\.path\b/', ohneKommentare($datei)) === 1 && ! str_contains($datei, 'Schema.php')) {
        $treffer[] = basename($datei);
    }
}

check(
    'kein Quelltext liest labels.path',
    $treffer === [],
    implode(', ', $treffer)
);

echo "\n2 · TASK-002 · relation_records.path bleibt stehen — und warum\n";

// ⚠️ **Die umgekehrte Zusage, und sie ist Absicht** (`INF-051`,
// [`inbox.md`](../../docs/pakete/modelltabellen/inbox.md)). *TASK-002 nennt die Spalte einen «reinen
// Spiegel von `relation_id`». **Gemessen am 2026-09-05 ist sie das nur in der lebenden Tabelle** —
// 0 von 103 Abweichungen dort, aber **1119 von 5569 im Schatten**, davon 1117 mit mehrteiligem Pfad.
// Der Spiegel ist der augenblickliche Zustand einer Tabelle, in der gerade keine Einstellung an einer
// Verwendungsstelle steht.*
//
// ⚠️ **Was ein Streichen kostete, ist kein Aufraeumen, sondern ein stiller Verlust:** *«diese
// Einstellung, ueberall» und «diese Einstellung, nur an dieser Verwendungsstelle» fielen auf
// **dieselbe** Zeile — gleiche `node_record_id`, gleiche `relation_id`, gleicher `locale`. Die
// zweite ueberschriebe die erste, ohne dass irgendetwas rot wuerde.*
//
// ⚠️ *Bis er entscheidet, haelt dieser Abschnitt den Zustand fest, statt ihn vorwegzunehmen (`PR-4`).
// **Den Rundlauf selbst prueft `setting-write-check`** — «und zwar im Satz des Besitzers, unter der
// zweistufigen Adresse». Hier steht nur, dass der Traeger dieser Adresse noch da ist.*
$werte = Schema::table('relation_records');

check('relation_records traegt weiter die Spalte path', hatSpalte($werte, 'path'));

// ⚠️ **Und der Kern schreibt die zweistufige Adresse wirklich** — sonst waere die Spalte oben ein
// Denkmal. *Gesucht im Quelltext **ohne Kommentare**: die drei Stellen, die einen Pfad aus mehr als
// einem Abschnitt bauen.*
$eintrag = ohneKommentare(dirname(__DIR__, 2) . '/src/Core/Service/DataEntry.php');

check(
    'putSettingAtUseSite baut Verwendungsstelle . Einstellungskante',
    preg_match('/\$pfad\s*=\s*\$relationId\s*\.\s*\'\.\'\s*\.\s*\$kante->id/', $eintrag) === 1
);

check(
    'clearSettingAtUseSite loescht unter derselben Adresse',
    preg_match('/clearPath\(\s*\$satz->id,\s*\$relationId\s*\.\s*\'\.\'\s*\.\s*\$settingRelationId/', $eintrag) === 1
);

check(
    'createPartAt setzt die ganze Kette zusammen',
    preg_match('/implode\(\'\.\',\s*\$relationIds\)/', $eintrag) === 1
);

// ⚠️ *Und die Leser entscheiden **am Pfad** und nicht an `relation_id` — genau die Unterscheidung,
// die mit der Spalte fiele.*
check(
    'die Leser schlagen ueber den Pfad nach',
    substr_count($eintrag, '$gesucht[$wert->path]') >= 2
);

echo "\n" . ($bad === 0 ? "Alles gruen: $ok\n" : "$bad fehlgeschlagen, $ok in Ordnung\n");

exit($bad === 0 ? 0 : 1);
