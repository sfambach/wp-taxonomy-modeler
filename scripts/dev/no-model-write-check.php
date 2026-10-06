<?php declare(strict_types=1);

/**
 * Kein Wächter schreibt in das Modell des Eigentümers — **und das ist prüfbar**.
 *
 *     php scripts/dev/no-model-write-check.php
 *
 * ⚠️ **Der Fall, den es wirklich gab, und er stand auf seinem Bildschirm.** *Am 2026-09-06 sah der
 * Eigentümer die Einstellung `read_only` **zweimal** an seinem `Integer`. Gemessen war die zweite
 * Kante `Integer --read_only--> Constants`, **von `package7-check.php` angelegt und
 * liegengelassen**. Am selben Tag lagen drei Knoten `__cv Zahl` aus `converter-check.php` unter
 * demselben `Integer`, und `seed-twice-check.php` hatte dreimal eine deutsche `Adresse` gesät.
 * **Dieselbe Krankheit in drei Läufen.**
 *
 * ```mermaid
 * flowchart LR
 *   W["jeder Waechter mit wp-load"] --> K["laedt lib/no-write.php"]
 *   K --> T["START TRANSACTION"]
 *   T --> R["ROLLBACK beim Herunterfahren"]
 *   W -.->|"ohne Klammer"| F["rot"]
 * ```
 *
 * **Aufräumen am Ende war die alte Zusage, und sie hält nicht:** ein Lauf, der in Zeile 200 rot
 * wird, erreicht seine Zeile 400 nie. Die Klammer aus {@see lib/no-write.php} hängt am
 * Herunterfahren des Prozesses und greift darum auch nach `exit(1)`.
 *
 * **Geprüft wird viererlei — drei am Kode ohne Datenbank, das vierte an zwei Prozessen, ohne das
 * Modell zu berühren:**
 *
 * 1. **Jeder Wächter, der WordPress lädt, lädt unmittelbar danach die Klammer.**
 * 2. **Kein Wächter sagt `START TRANSACTION`** — das bestätigt in MySQL stillschweigend alles
 *    Bisherige und schriebe genau den Rückstand fest, den die Klammer verhindert. Wer innerhalb
 *    eines Laufs zurückdrehen will, nimmt einen `SAVEPOINT`.
 * 3. **Jede Ausnahme steht hier mit Grund, und die Datei dazu gibt es noch.** *Eine Ausnahme für
 *    eine Datei, die es nicht mehr gibt, ist eine Zusage, die niemanden mehr bindet.*
 * 4. **Ein Lauf zur Zeit** (TASK-073): *hält ein Prozess die Klammer, geht der zweite mit ihrem
 *    eigenen Rückgabewert und ihrem Satz — ohne zu warten, bis der erste fertig ist —, und nach dem
 *    Ende des ersten bekommt der nächste sie wieder.* Die Probe dazu ist {@see lib/klammer-probe.php}.
 *
 * @see tests/README.md
 * @see scripts/dev/lib/no-write.php
 */

$verzeichnis = __DIR__;

/**
 * Wer die Klammer nicht tragen kann, mit Grund — **und ohne Grund steht hier niemand**.
 *
 * @var array<string, string>
 */
$ausnahmen = [
    // Der Lauf startet sich selbst als Kindprozess, der einen echten POST abschickt. Ein
    // Kindprozess hat eine **eigene** Verbindung und sähe von einer offenen Umklammerung des
    // Elternprozesses nichts — der Knoten, auf den er postet, wäre für ihn nicht da.
    'labels-page-save-check.php' => 'Kindprozess mit eigener Verbindung',
];

$rot = 0;

$sage = static function (bool $ok, string $was, string $dazu = '') use (&$rot): void {
    printf("  %-4s %s%s\n", $ok ? 'ok' : 'FAIL', $was, $dazu === '' ? '' : "  ({$dazu})");

    if (! $ok) {
        ++$rot;
    }
};

echo "== 1. jede Ausnahme meint eine Datei, die es gibt ==\n";

foreach ($ausnahmen as $datei => $grund) {
    $sage(is_readable($verzeichnis . '/' . $datei), "Ausnahme {$datei}", $grund);
}

echo "\n== 2. wer WordPress laedt, laedt die Klammer ==\n";

$ohne = [];
$spaet = [];
$geklammert = 0;

foreach (glob($verzeichnis . '/*-check.php') ?: [] as $pfad) {
    $name = basename($pfad);

    if ($name === basename(__FILE__)) {
        continue;
    }

    $zeilen = file($pfad, FILE_IGNORE_NEW_LINES) ?: [];
    $wpLoad = null;

    foreach ($zeilen as $i => $zeile) {
        if (preg_match('~^require .*wp-load\.php\';$~', trim($zeile)) === 1) {
            $wpLoad = $i;

            break;
        }
    }

    if ($wpLoad === null) {
        continue;
    }

    if (isset($ausnahmen[$name])) {
        continue;
    }

    // ⚠️ **Unmittelbar danach und nicht irgendwo** — *eine Klammer hinter dem ersten Schreibvorgang
    // ist keine. Leerzeilen und Kommentare dürfen dazwischen stehen, eine Anweisung nicht.*
    $stelle = null;

    for ($i = $wpLoad + 1, $n = count($zeilen); $i < $n; $i++) {
        $zeile = trim($zeilen[$i]);

        if ($zeile === '' || str_starts_with($zeile, '//') || str_starts_with($zeile, '*') || str_starts_with($zeile, '/*')) {
            continue;
        }

        $stelle = $zeile;

        break;
    }

    if ($stelle === "require __DIR__ . '/lib/no-write.php';") {
        ++$geklammert;

        continue;
    }

    if (str_contains(file_get_contents($pfad) ?: '', 'lib/no-write.php')) {
        $spaet[] = $name;

        continue;
    }

    $ohne[] = $name;
}

$sage($ohne === [], "alle Waechter mit wp-load tragen die Klammer ({$geklammert} Stueck)", implode(', ', $ohne));
$sage($spaet === [], 'und keiner traegt sie zu spaet', implode(', ', $spaet));

echo "\n== 3. kein zweites START TRANSACTION ==\n";

$zweite = [];

foreach (glob($verzeichnis . '/*-check.php') ?: [] as $pfad) {
    if (basename($pfad) === basename(__FILE__)) {
        continue;
    }

    foreach (file($pfad, FILE_IGNORE_NEW_LINES) ?: [] as $zeile) {
        // Nur der Aufruf zählt, nicht die Erklärung darüber.
        if (str_contains($zeile, "'START TRANSACTION'")) {
            $zweite[] = basename($pfad);

            break;
        }
    }
}

$sage($zweite === [], 'kein Waechter beginnt eine eigene Umklammerung', implode(', ', $zweite));

echo "\n== 4. ein Lauf zur Zeit: der zweite bricht ab, statt zu haengen ==\n";

// ⚠️ **Der einzige Abschnitt mit Datenbank, und er berührt das Modell nicht** (TASK-073). *Die
// Sperre ist eine Verbindungssperre in MySQL; ob sie hält, zeigt sich nur an zwei echten Prozessen.
// Der erste nimmt sie und wartet, der zweite muss mit dem eigenen Rückgabewert der Klammer gehen —
// **nicht grün, nicht rot, und vor allem nicht minutenlang stumm**. Gemessen am 2026-09-09 starb
// `cleanup-screen-check` genau an dem Fall, den dieser Abschnitt seither ausschliesst.*
$wpRoot = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';

if (! is_readable($wpRoot . '/wp-load.php')) {
    $sage(false, 'WordPress gefunden', "kein wp-load.php unter {$wpRoot} — WP_ROOT setzen");
} else {
    $php   = PHP_BINARY;
    $probe = $verzeichnis . '/lib/klammer-probe.php';
    $halte = 6;

    $erster = proc_open(
        [$php, $probe, $wpRoot, (string) $halte],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $rohre
    );

    $sage(is_resource($erster), 'der erste Lauf startet');

    if (is_resource($erster)) {
        // Erst weiter, wenn der erste die Sperre wirklich hält — er sagt es auf seiner Ausgabe.
        $gehalten = trim((string) fgets($rohre[1]));
        $sage($gehalten === 'Klammer gehalten', 'der erste Lauf haelt die Klammer', $gehalten);

        $start   = microtime(true);
        $ausgabe = [];
        $code    = 1;
        exec(escapeshellarg($php) . ' ' . escapeshellarg($probe) . ' ' . escapeshellarg($wpRoot) . ' 0 2>&1', $ausgabe, $code);
        $dauer = microtime(true) - $start;
        $text  = implode("\n", $ausgabe);

        $sage($code === 3, 'der zweite Lauf geht mit dem Rueckgabewert der Klammer (3)', "kam: {$code}");
        $sage(str_contains($text, 'ein anderer Wächterlauf hält die Sperre'), 'und sagt, wer ihn aufhaelt', mb_substr($text, 0, 120));
        $sage(! str_contains($text, 'Klammer gehalten'), 'und hat die Klammer nie bekommen');
        $sage($dauer < $halte, sprintf('und wartet nicht auf das Ende des ersten (%.1fs von %ds)', $dauer, $halte));

        fclose($rohre[1]);
        fclose($rohre[2]);
        $ende = proc_close($erster);
        $sage($ende === 0, 'der erste Lauf endet gruen, die Sperre faellt mit ihm', "kam: {$ende}");

        // Und die Gegenprobe: nach dem Ende des ersten bekommt ein Lauf die Sperre sofort.
        $ausgabe = [];
        exec(escapeshellarg($php) . ' ' . escapeshellarg($probe) . ' ' . escapeshellarg($wpRoot) . ' 0 2>&1', $ausgabe, $code);
        $sage($code === 0, 'danach bekommt der naechste Lauf die Sperre wieder', "kam: {$code}");
    }
}

echo "\n" . ($rot === 0 ? "alles gruen\n" : "{$rot} rot\n");

exit($rot === 0 ? 0 : 1);
