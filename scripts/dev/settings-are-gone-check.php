<?php declare(strict_types=1);

/**
 * Steht im Entscheidungslog noch ein Satz, den [D-506](docs/NewConcept/90-decision-log.md) widerlegt?
 *
 * ⚠️ **Der Eigentümer hat die Gefahr benannt, und sie ist keine theoretische:** *«wenn Du die Dokumente
 * durchguckst, findest Du die zuerst und behauptest dann, es wär so. Das ist die Gefahr, die ich sehe.»*
 * **Genau das ist an diesem Tag mehrfach passiert** — zuletzt an `D-217`, das seine Warnung seit dem
 * Vortag trug und aus dessen Text darunter trotzdem argumentiert wurde.
 *
 * ⚠️ **Darum wird geteilt statt markiert** ([D-506](docs/NewConcept/90-decision-log.md)): die überholte
 * Hälfte liegt im [Dachboden](docs/NewConcept/92-veraltete-entscheidungen.md), die geltende bleibt als
 * kurze Zeile im Log. *Wer greppt, trifft zuerst die Warnung und liest nur noch Wahres.*
 *
 * ⚠️ *Diese Prüfung ersetzt meine Sorgfalt durch eine Messung. Sie kennt die Sätze, die das neue Modell
 * widerlegt, und verlangt für jeden einen Überholt-Vermerk — oder dass die Zeile selbst zum neuen Modell
 * gehört und den Satz nur **zitiert**, um ihn zu widerlegen.*
 *
 * Usage: php scripts/dev/settings-are-gone-check.php
 *
 * @see docs/NewConcept/02-field-and-setting.md
 */

$log = __DIR__ . '/../../docs/NewConcept/90-decision-log.md';

/**
 * Sätze, die [D-505](docs/NewConcept/90-decision-log.md)/[D-506](docs/NewConcept/90-decision-log.md)
 * widerlegen.
 *
 * ⚠️ *Absichtlich eng: gesucht wird die **Behauptung**, nicht das Wort. «setting» kommt in hundert
 * Zeilen vor, die nichts über den Mechanismus sagen — eine Prüfung, die dort anschlägt, wird
 * abgeschaltet statt befolgt.*
 */
const WIDERLEGT = [
    'ein Setting hat keine Multiplizität' => '/has no multiplicity|hat keine Multiplizität/iu',
    'Kopie beim Erben'                    => '/materiali[sz]e?d? into the inheriting|written into the inheriting/iu',
    'Settings haben eine eigene Tabelle'  => '/settings (?:get|have) (?:their|its) own table/iu',
    'ein Schlüssel ist ein Name'          => '/`setting_key` is a \*\*varchar\*\*|second key space/iu',
];

/** Was eine Zeile freispricht: sie trägt einen Vermerk, oder sie gehört zum neuen Modell. */
// ⚠️ *Ohne `i` fiel `D-449` durch, weil es «Superseded» gross schreibt — die Pruefung hatte den
// Fehler, den sie suchen sollte, in sich selbst.*
const FREISPRUCH = '/Überholt durch|Verschoben in den|teilweise überholt|superseded|D-505|D-506/iu';

$zeilen = file($log);

if ($zeilen === false) {
    fwrite(STDERR, "90-decision-log.md ist nicht lesbar.\n");

    exit(2);
}

$gefunden = [];
$geprueft = 0;

foreach ($zeilen as $z) {
    if (! preg_match('/^\| (D-\d+) \|/', $z, $m)) {
        continue;
    }

    ++$geprueft;

    foreach (WIDERLEGT as $was => $re) {
        if (! preg_match($re, $z)) {
            continue;
        }

        // ⚠️ *Der Freispruch wird im **ganzen** Eintrag gesucht und nicht nur im Kopf: eine Zeile darf
        // den widerlegten Satz auch mitten im Text zitieren, solange sie ihn dabei widerlegt.*
        if (preg_match(FREISPRUCH, $z)) {
            continue;
        }

        $gefunden[$m[1]][] = $was;
    }
}

if ($geprueft < 100) {
    printf("Nur %d Entscheidungen gelesen — das Log wurde nicht richtig geparst.\n", $geprueft);

    exit(1);
}

printf("Entscheidungen geprueft: %d\n", $geprueft);
printf("Saetze, die das neue Modell widerlegt: %d\n\n", count(WIDERLEGT));

if ($gefunden === []) {
    echo "  ok   keine widerlegte Behauptung steht ohne Vermerk im Log\n\nall green\n";

    exit(0);
}

foreach ($gefunden as $id => $saetze) {
    printf("  FEHLT %-8s behauptet ohne Vermerk: %s\n", $id, implode(' · ', array_unique($saetze)));
}

printf(
    "\n%d Entscheidung(en) behaupten etwas, das [D-506] widerlegt, ohne es zu sagen.\n"
    . "Teilen: die ueberholte Haelfte in den Dachboden, die geltende bleibt kurz im Log.\n",
    count($gefunden)
);

exit(1);
