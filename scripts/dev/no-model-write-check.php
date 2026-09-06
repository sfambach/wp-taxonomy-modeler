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
 * **Geprüft wird dreierlei, alles am Kode und ohne Datenbank:**
 *
 * 1. **Jeder Wächter, der WordPress lädt, lädt unmittelbar danach die Klammer.**
 * 2. **Kein Wächter sagt `START TRANSACTION`** — das bestätigt in MySQL stillschweigend alles
 *    Bisherige und schriebe genau den Rückstand fest, den die Klammer verhindert. Wer innerhalb
 *    eines Laufs zurückdrehen will, nimmt einen `SAVEPOINT`.
 * 3. **Jede Ausnahme steht hier mit Grund, und die Datei dazu gibt es noch.** *Eine Ausnahme für
 *    eine Datei, die es nicht mehr gibt, ist eine Zusage, die niemanden mehr bindet.*
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

echo "\n" . ($rot === 0 ? "alles gruen\n" : "{$rot} rot\n");

exit($rot === 0 ? 0 : 1);
