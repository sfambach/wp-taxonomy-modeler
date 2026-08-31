<?php declare(strict_types=1);

/**
 * Das Regelverzeichnis — alle Regeln des Projekts an einer Stelle, erzeugt statt gepflegt.
 *
 *     php scripts/dev/rules-index.php            schreibt docs/NewConcept/02-rules-index.md
 *     php scripts/dev/rules-index.php --check    wird rot, wenn das Verzeichnis veraltet ist
 *
 * ⚠️ **Dieses Skript gibt es, weil der Eigentümer den Regelsatz nicht übergeben konnte.** *Seine
 * Worte am 2026-09-01: «hätte ein Problem den aktuellen Regelsatz zu übergeben, da er Chaos enthält,
 * deswegen hatte ich gebeten den aufzuräumen, aber das ist nie passiert.»*
 *
 * ⚠️ **Der Befund, gemessen am selben Tag, und er ist nicht der erwartete:** *Es sind **306 Regeln in
 * dreizehn Buchstabenräumen**, und **272 davon werden irgendwo wieder zitiert** — sie sind also
 * lebendig, nicht tot. Das Chaos ist nicht Altlast, sondern **Streuung**: 111 `C`-Regeln stecken in
 * `10-domain-core.md`, 76 `R`-Regeln in `30-renderer.md`, 22 `M`-Regeln in `70-migration.md`, und
 * **`CLAUDE.md` — die einzige Datei, die wie ein Regelsatz aussieht — enthält rund 25 davon, also
 * 8 %.** Vor einer Aufgabe konnte niemand wissen, welche Regeln für sie gelten.*
 *
 * ⚠️ **Warum erzeugt und nicht geschrieben:** *Ein von Hand gepflegtes Verzeichnis wäre am zweiten Tag
 * falsch — genau wie die Zählung im Kopf von `CLAUDE.md`, die zweimal daneben lag. **Der Prüfmodus ist
 * der eigentliche Wert**, nicht die Datei: er geht rot, sobald jemand eine Regel einführt, ohne dass
 * sie im Verzeichnis auftaucht.*
 *
 * ⚠️ **Was dieses Skript ausdrücklich nicht tut: aussortieren.** *Welche zwei Regeln dasselbe sagen
 * und welche überflüssig ist, entscheidet der Eigentümer (`PR-4`). Hier wird nur sichtbar gemacht.*
 *
 * @see docs/NewConcept/02-rules-index.md
 */

$wurzel = dirname(__DIR__, 2);
$ziel   = $wurzel . '/docs/NewConcept/02-rules-index.md';
$pruefe = in_array('--check', $argv, true);

/**
 * Buchstabenräume, die keine Regeln sind.
 *
 * ⚠️ *`D` und `OQ` sind Entscheidungen und Fragen, der Rest sind Einheiten und Abkürzungen, die
 * zufällig auf das Muster passen. **Ohne diese Liste stünden 562 Entscheidungen im Regelverzeichnis.***
 */
const KEINE_REGEL = ['D', 'OQ', 'KB', 'MB', 'GB', 'PHP', 'MVC', 'SQL', 'HTML', 'CSS', 'ID', 'UI', 'API', 'CLI', 'PSR', 'UTF'];

/**
 * Wofür ein Buchstabenraum steht.
 *
 * ⚠️ *Von Hand, weil es nirgends steht — und genau das ist Teil des Befunds. **Ein Raum ohne Eintrag
 * hier erscheint im Verzeichnis als «ohne Namen»**, was ein Hinweis ist und kein Fehler.*
 */
const RAEUME = [
    'PR' => 'Prozess — wie gearbeitet wird',
    'CD' => 'Code — wie geschrieben wird',
    'AR' => 'Architektur — was gebaut wird (braucht je eine Entscheidung)',
    'DC' => 'Dokumentation im Code',
    'V'  => 'Vision und Umfang',
    'C'  => 'Modellkern',
    'P'  => 'Speicherung in WordPress',
    'U'  => 'Bedienung',
    'R'  => 'Renderer — wie gezeichnet wird',
    'I'  => 'Sprachen und Übersetzung',
    'K'  => 'Rechnen',
    'M'  => 'Migration',
    'A'  => 'Standardbaum — Aufbau',
    'B'  => 'Standardbaum — Inhalt',
    'S'  => 'Speicher',
    'Q'  => 'Aus der Altlast übernommene Frage',
];

/** @return array<string, list<string>> Pfad => Zeilen */
function quellen(string $wurzel): array
{
    $aus = [];

    $lauf = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wurzel . '/docs/NewConcept'));

    foreach ($lauf as $datei) {
        if (! $datei instanceof SplFileInfo || $datei->getExtension() !== 'md') {
            continue;
        }

        $pfad = str_replace('\\', '/', $datei->getPathname());

        // Das Verzeichnis selbst zählt nicht als Fundort — sonst definiert es sich selbst.
        if (str_ends_with($pfad, '02-rules-index.md')) {
            continue;
        }

        $aus[substr($pfad, strlen(str_replace('\\', '/', $wurzel)) + 1)] = file($datei->getPathname()) ?: [];
    }

    $aus['CLAUDE.md'] = file($wurzel . '/CLAUDE.md') ?: [];

    ksort($aus);

    return $aus;
}

/**
 * Die Zeile, die eine Regel am wahrscheinlichsten **definiert**.
 *
 * ⚠️ *Eine Tabellenzeile oder Überschrift, die mit der Regel beginnt, schlägt jede Erwähnung im
 * Fliesstext — und unter gleichrangigen gewinnt die längere. **Das ist eine Heuristik**, deshalb steht
 * unten, wieviele keine erkennbare Definitionszeile haben.*
 */
function definitionsort(array $quellen): array
{
    $fund = [];

    foreach ($quellen as $pfad => $zeilen) {
        foreach ($zeilen as $nr => $z) {
            if (! preg_match_all('/\*\*([A-Z]{1,3})-?(\d{1,3})([a-z]?)\*\*/', $z, $treffer, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($treffer as $m) {
                if (in_array($m[1], KEINE_REGEL, true)) {
                    continue;
                }

                $id = $m[1] . $m[2] . $m[3];

                // ⚠️ *`$m[0]` **enthält die Sternchen bereits** — sie hier noch einmal davorzusetzen
                // hiess vier statt zwei, und dann galten alle 306 Regeln als bloss erwähnt.
                $marke = preg_quote($m[0], '/');

                $istDefinition = preg_match('/^\s*\|\s*' . $marke . '/', $z) === 1
                    || preg_match('/^#+\s*' . $marke . '/', $z) === 1
                    || preg_match('/^\s*' . $marke . '/', $z) === 1;

                $punkte = ($istDefinition ? 100000 : 0) + strlen(trim($z));

                if (! isset($fund[$id]) || $punkte > $fund[$id]['punkte']) {
                    $fund[$id] = [
                        'punkte'     => $punkte,
                        'raum'       => $m[1],
                        'nummer'     => (int) $m[2],
                        'zusatz'     => $m[3],
                        'datei'      => $pfad,
                        'zeile'      => $nr + 1,
                        'text'       => trim($z),
                        'definiert'  => $istDefinition,
                    ];
                }
            }
        }
    }

    return $fund;
}

/** Der kurze Satz, der sagt, was die Regel regelt. */
function kurzfassung(string $zeile, string $marke): string
{
    $p = mb_strpos($zeile, $marke);

    $rest = $p === false ? $zeile : mb_substr($zeile, $p + mb_strlen($marke));

    // Tabellentrenner, Auszeichnung und Verweise weg — es soll ein Satz sein, kein Markup.
    $rest = (string) preg_replace('/^\s*\|\s*/', '', $rest);
    $rest = explode('|', $rest)[0];
    $rest = (string) preg_replace('/\[([^\]]+)\]\([^)]*\)/', '$1', $rest);
    $rest = str_replace(['**', '`', '⚠️', '*'], '', $rest);
    $rest = trim((string) preg_replace('/\s+/', ' ', $rest), " \t—–-:·");

    if ($rest === '') {
        return '—';
    }

    return mb_strlen($rest) > 150 ? mb_substr($rest, 0, 147) . '…' : $rest;
}

$quellen = quellen($wurzel);
$fund    = definitionsort($quellen);

if ($fund === []) {
    fwrite(STDERR, "Keine einzige Regel erkannt — das Muster passt nicht mehr.\n");
    exit(2);
}

// Zitate zählen: wie oft steht die Regel ausserhalb ihrer Definitionszeile?
$allesText = '';

foreach ($quellen as $zeilen) {
    $allesText .= implode('', $zeilen);
}

$lauf = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wurzel . '/src'));

foreach ($lauf as $datei) {
    if ($datei instanceof SplFileInfo && $datei->getExtension() === 'php') {
        $allesText .= (string) file_get_contents($datei->getPathname());
    }
}

// ⚠️ *Wortgrenzen, **nicht** `substr_count`: `R1` steckt in `R10`, `R14` und `R100`. Ohne die Grenze
// meldete das Verzeichnis 346 Zitate für `R1` — eine erfundene Zahl an prominenter Stelle, also genau
// die Sorte Beleg, gegen die dieses Projekt seine Wächter baut.*
foreach ($fund as $id => $f) {
    $muster = '/\b' . $f['raum'] . '-?' . $f['nummer'] . $f['zusatz'] . '\b/';

    $fund[$id]['zitate'] = max(0, preg_match_all($muster, $allesText) - 1);
}

// Nach Raum, dann Nummer.
uasort($fund, static function (array $a, array $b): int {
    $ra = array_search($a['raum'], array_keys(RAEUME), true);
    $rb = array_search($b['raum'], array_keys(RAEUME), true);

    $ra = $ra === false ? 99 : $ra;
    $rb = $rb === false ? 99 : $rb;

    return [$ra, $a['raum'], $a['nummer'], $a['zusatz']] <=> [$rb, $b['raum'], $b['nummer'], $b['zusatz']];
});

$nachRaum = [];

foreach ($fund as $id => $f) {
    $nachRaum[$f['raum']][$id] = $f;
}

$ohneDefinition = 0;

foreach ($fund as $f) {
    if (! $f['definiert']) {
        ++$ohneDefinition;
    }
}

// ------------------------------------------------------------------ das Blatt

$b = [];

$b[] = '# Regelverzeichnis — alle Regeln des Projekts';
$b[] = '';
$b[] = '**Diese Datei wird erzeugt.** `php scripts/dev/rules-index.php` schreibt sie neu,';
$b[] = '`--check` wird rot, wenn sie veraltet ist. **Nichts hier von Hand ändern** — die Regel selbst';
$b[] = 'steht in der Datei, auf die verwiesen wird, und nur dort wird sie geändert.';
$b[] = '';
$b[] = '⚠️ **Warum es dieses Verzeichnis gibt.** *Der Eigentümer konnte den Regelsatz nicht übergeben:';
$b[] = '«hätte ein Problem den aktuellen Regelsatz zu übergeben, da er Chaos enthält». Gemessen war das';
$b[] = 'Chaos nicht Altlast — **' . count($fund) . ' Regeln in ' . count($nachRaum) . ' Räumen, die meisten davon lebendig zitiert** —,';
$b[] = 'sondern **Streuung**: sie stehen in ' . count(array_unique(array_column($fund, 'datei'))) . ' verschiedenen Dateien, und **keine Stelle listete sie auf.**';
$b[] = 'Vor einer Aufgabe konnte niemand wissen, welche Regeln für sie gelten.*';
$b[] = '';
$b[] = '⚠️ **Dieses Verzeichnis sortiert nicht aus.** *Welche zwei Regeln dasselbe sagen und welche';
$b[] = 'überflüssig ist, entscheidet der Eigentümer (`PR-4`). Hier wird nur sichtbar gemacht, was da ist.*';
$b[] = '';
$b[] = '| Raum | Wofür | Regeln |';
$b[] = '|---|---|---|';

foreach ($nachRaum as $raum => $regeln) {
    $b[] = sprintf(
        '| **%s** | %s | %d |',
        $raum,
        RAEUME[$raum] ?? '*ohne Namen — dieser Raum ist nirgends erklärt*',
        count($regeln)
    );
}

$b[] = sprintf('| | **Summe** | **%d** |', count($fund));
$b[] = '';
$b[] = sprintf(
    'Bei **%d** Regeln liess sich keine Definitionszeile erkennen, nur eine Erwähnung — dort steht die Regel',
    $ohneDefinition
);
$b[] = 'vermutlich im Fliesstext und sollte eine eigene Zeile bekommen.';
$b[] = '';
$b[] = '---';

foreach ($nachRaum as $raum => $regeln) {
    $b[] = '';
    $b[] = sprintf('## %s — %s', $raum, RAEUME[$raum] ?? '*ohne Namen*');
    $b[] = '';
    $b[] = '| Regel | Was sie sagt | Steht in | Zitiert |';
    $b[] = '|---|---|---|---|';

    foreach ($regeln as $id => $f) {
        $marke = '**' . $f['raum'] . '-' . $f['nummer'] . $f['zusatz'] . '**';

        if (! str_contains($f['text'], $marke)) {
            $marke = '**' . $id . '**';
        }

        $wohin = $f['datei'] === 'CLAUDE.md' ? '../../CLAUDE.md' : basename($f['datei']);

        $b[] = sprintf(
            '| **%s** | %s | [%s:%d](%s) | %s |',
            $id,
            str_replace('|', '\\|', kurzfassung($f['text'], $marke)),
            basename($f['datei']),
            $f['zeile'],
            $wohin,
            $f['zitate'] > 0 ? (string) $f['zitate'] : '**nie**'
        );
    }
}

$b[] = '';

$blatt = implode("\n", $b) . "\n";

if ($pruefe) {
    $ist = is_readable($ziel) ? (string) file_get_contents($ziel) : '';

    // Zeilenenden neutral vergleichen — git wandelt die Arbeitskopie nach CRLF.
    if (str_replace("\r\n", "\n", $ist) === $blatt) {
        printf("Regelverzeichnis aktuell: %d Regeln in %d Räumen.\n", count($fund), count($nachRaum));

        exit(0);
    }

    fwrite(
        STDERR,
        "Das Regelverzeichnis ist veraltet.\n"
        . "Es gibt " . count($fund) . " Regeln; docs/NewConcept/02-rules-index.md sagt etwas anderes.\n"
        . "Neu erzeugen mit: php scripts/dev/rules-index.php\n"
    );

    exit(1);
}

file_put_contents($ziel, $blatt);

printf(
    "Geschrieben: %s\n%d Regeln in %d Räumen, aus %d Dateien.\n",
    'docs/NewConcept/02-rules-index.md',
    count($fund),
    count($nachRaum),
    count(array_unique(array_column($fund, 'datei')))
);

exit(0);
