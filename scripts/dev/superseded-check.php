<?php declare(strict_types=1);

/**
 * Wer beruft sich auf eine Entscheidung, die zurückgenommen wurde?
 *
 *     php scripts/dev/superseded-check.php
 *
 * ⚠️ **Diese Prüfung gibt es, weil der Eigentümer nach einer Struktur gefragt hat und nicht nach mehr
 * Fleiss.** *«Diese Widersprüche, hab ich gesagt, müssen wir ausräumen und aufräumen. Deswegen bitte ich
 * dich immer wieder aufzuräumen, aber das klappt nicht. Ich frage mich, wie wir irgendwie strukturierter
 * an die Sache drangehen können.»*
 *
 * ⚠️ **Der Fall, der sie ausgelöst hat, ist nachvollziehbar und dauerte zehn Tage:**
 *
 * | | |
 * |---|---|
 * | **D-134** (22.08.) | *«`record_values` keys on a **path** … **Required by D-133's flattening**»* |
 * | **D-133** | trägt oben *«Überholt durch D-232»* |
 * | **D-232** (23.08.) | *«The branch decides where a value is stored, **not** the multiplicity. Supersedes D-133.»* |
 *
 * *Der Grund für die Spalte fiel am Tag nach ihrer Einführung. Die Spalte blieb. Gemessen am 2026-09-01
 * haben **183 von 183** Wertzeilen `path === edge_id` — die zusammengesetzte Form hat **keinen einzigen
 * Bestand**. Und dieselbe Bewegung noch einmal: [D-527](../../docs/NewConcept/90-decision-log.md) sagte
 * «mehrere Werte sind mehrere **Pfade**», [D-530](../../docs/NewConcept/90-decision-log.md) ersetzte es
 * durch «mehrere **Zeilen**, geordnet durch `position`».*
 *
 * ⚠️ **Was `references-check.php` prüft und was hier fehlte:** *dort geht es darum, ob eine zitierte Id
 * **existiert** — so kamen sieben Entscheidungen zutage, die nur in Docblocks standen. **Ob sie noch
 * gilt, prüfte niemand.** Ein Verweis auf eine zurückgenommene Entscheidung ist schlimmer als ein
 * baumelnder: er sieht gültig aus.*
 *
 * ⚠️ **Kein Klassifizierer, sondern ein Hauptbuch mit Decke** — *wie `doc-reach-check.php` es hat. Viele
 * dieser Verweise sind richtig: wer eine Entscheidung ablöst, muss sie nennen, und wer Geschichte
 * erzählt, zitiert sie. **Mechanisch trennen lässt sich «als Geschichte» nicht von «als Grund»**; also
 * wird gezählt, und die Zahl darf nur fallen. Wer sie erhöht, muss es begründen.*
 *
 * @see docs/NewConcept/90-decision-log.md
 */

/**
 * Wieviele Verweise auf zurückgenommene Entscheidungen hingenommen werden.
 *
 * ⚠️ *Gemessen am 2026-09-01: **108**. Diese Zahl ist die Schuld, nicht das Ziel — sie darf sinken und
 * nie steigen. Wer sie anhebt, schreibt daneben, warum.*
 */
const HINGENOMMEN = 108;

$log = dirname(__DIR__, 2) . '/docs/NewConcept/90-decision-log.md';

if (! is_readable($log)) {
    fwrite(STDERR, "Kein Entscheidungsbuch unter {$log}\n");
    exit(2);
}

$zeilen = file($log);

if ($zeilen === false) {
    fwrite(STDERR, "Nicht lesbar.\n");
    exit(2);
}

/** @var array<string, string> Id => die Zeile, in der sie steht */
$entscheidungen = [];

/** @var array<string, true> Die zurückgenommenen */
$ueberholt = [];

foreach ($zeilen as $z) {
    if (! preg_match('/^\| (D-\d+) \|/', $z, $t)) {
        continue;
    }

    $entscheidungen[$t[1]] = $z;

    // ⚠️ *Drei Schreibweisen, weil das Buch zweisprachig gewachsen ist. **Eine vierte fiele hier
    // durch** — deshalb wird unten geprüft, dass überhaupt welche gefunden wurden.*
    if (
        str_contains($z, 'Überholt durch')
        || str_contains($z, 'Superseded by')
        || str_contains($z, 'superseded by')
    ) {
        $ueberholt[$t[1]] = true;
    }
}

echo 'Entscheidungen:            ', count($entscheidungen), "\n";
echo 'davon zurückgenommen:      ', count($ueberholt), "\n";

if ($entscheidungen === []) {
    fwrite(STDERR, "Keine Entscheidungszeile erkannt — das Muster passt nicht mehr.\n");
    exit(2);
}

// ⚠️ **Der Gegenfall, und ohne ihn wiegt der Lauf nichts.** *Findet die Markierung nichts, ist jede
// Zählung null und alles grün — auch wenn das Buch voller zurückgenommener Entscheidungen steht.
if ($ueberholt === []) {
    fwrite(STDERR, "Keine einzige Rücknahme erkannt — die Markierung heisst offenbar anders.\n");
    exit(2);
}

/** @var array<string, list<string>> Wer beruft sich auf wen */
$verweise = [];

foreach ($entscheidungen as $id => $zeile) {
    // Eine Rücknahme darf die zurückgenommene nennen — sie muss.
    if (isset($ueberholt[$id])) {
        continue;
    }

    foreach (array_keys($ueberholt) as $alt) {
        if (str_contains($zeile, '[' . $alt . ']')) {
            $verweise[$id][] = $alt;
        }
    }
}

echo 'geltende, die eine zitieren: ', count($verweise), "\n\n";

foreach ($verweise as $wer => $welche) {
    echo '  ', $wer, ' → ', implode(', ', $welche), "\n";
}

$anzahl = count($verweise);

echo "\n";

if ($anzahl > HINGENOMMEN) {
    printf(
        "Die Zahl ist um %d gestiegen: %d statt %d.\n"
        . "Entweder den Verweis auflösen — die geltende Fassung nennen statt der zurückgenommenen —\n"
        . "oder, mit einer Entscheidung, die Decke in dieser Datei anheben.\n",
        $anzahl - HINGENOMMEN,
        $anzahl,
        HINGENOMMEN
    );

    exit(1);
}

if ($anzahl < HINGENOMMEN) {
    printf(
        "%d von %d hingenommenen — %d weniger. Die Decke in dieser Datei darf nachgezogen werden.\n",
        $anzahl,
        HINGENOMMEN,
        HINGENOMMEN - $anzahl
    );

    exit(0);
}

echo "Unverändert bei {$anzahl}.\n";

exit(0);
