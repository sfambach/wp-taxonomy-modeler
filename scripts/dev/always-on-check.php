<?php declare(strict_types=1);

/**
 * Wieviel muss ein Mensch **vor jeder Aufgabe** lesen?
 *
 *     php scripts/dev/always-on-check.php
 *
 * ⚠️ **Dieser Wächter macht `PR-13` zu einer Zahl.** *Die Regel sagt, Maßstab einer Spezifikation sei
 * die Prüfbarkeit durch einen Menschen ([D-566](../../docs/NewConcept/90-decision-log.md)) — aber sie
 * nennt keine Grenze, und **ohne Grenze ist sie eine Absicht.** Der Eigentümer hat danach gefragt:
 * «Was verhindert, dass es wieder 2688 KB werden?»*
 *
 * ⚠️ **Gedeckelt wird nicht das Gesamtwerk, sondern das Immer-Gelesene** — *und das ist die Lehre aus
 * der Diagnose des vorigen Anlaufs, die in [`CLAUDE.md`](../../CLAUDE.md) steht:*
 *
 * > *«The previous rule set grew to **82 KB, most of it always-on**, and became the main reason this
 * > project kept re-deciding the same questions.»*
 *
 * *Nicht die Menge war tödlich, sondern der Anteil, den man **immer** mitschleppt. Entscheidungsbuch,
 * Historie und Konzept bleiben ungedeckelt: sie werden **nachgeschlagen**, nicht gelesen.*
 *
 * ⚠️ **Die Decke ist die gemessene Zahl, nicht eine gewünschte** — *wie bei `superseded-check.php`.
 * Am 2026-09-01 waren es **42 KB**, nach dem Umzug der Herleitungen aus der Regeldatei in die
 * Entscheidungen, die sie tragen. **Das ist die Hälfte der tödlichen Dosis.** Sie darf fallen und nie
 * steigen; wer eine Regel hinzufügt, nimmt eine weg.*
 *
 * ⚠️ *Was der Wächter **nicht** kann: beurteilen, ob das Gelesene gut ist. Er zählt Bytes. Die Frage,
 * ob ein Mensch es halten kann, bleibt `PR-13` und wird gelesen, nicht gerechnet.*
 *
 * @see docs/arbeitsmodell.md
 */

/**
 * Was vor jeder Aufgabe gelesen werden muss.
 *
 * ⚠️ *Wächst diese Liste, wächst der Deckel nicht mit — **eine Datei hinzuzufügen heisst, anderswo
 * Platz zu schaffen.** Genau das ist der Zweck.*
 */
const IMMER_GELESEN = [
    'CLAUDE.md',
    'docs/arbeitsmodell.md',
    'AGENTS.md',
];

/**
 * Die Decke in Bytes.
 *
 * ⚠️ *43170 Bytes = 42.2 KB, gemessen am 2026-09-01. Die tödliche Dosis des vorigen Anlaufs war 82 KB.*
 */
const DECKE = 43170;

$wurzel = dirname(__DIR__, 2);

$summe   = 0;
$fehlend = [];

echo "Was vor jeder Aufgabe gelesen werden muss:\n\n";

foreach (IMMER_GELESEN as $pfad) {
    $voll = $wurzel . '/' . $pfad;

    if (! is_readable($voll)) {
        $fehlend[] = $pfad;

        continue;
    }

    $n = (int) filesize($voll);
    $summe += $n;

    printf("  %-30s %6d Bytes  %5.1f KB\n", $pfad, $n, $n / 1024);
}

// ⚠️ **Der Gegenfall.** *Fehlt eine Datei, ist die Summe klein und alles grün — auch wenn der
// Regelsatz verschwunden wäre.*
if ($fehlend !== []) {
    fwrite(STDERR, "\nNicht lesbar: " . implode(', ', $fehlend) . "\nOhne sie ist die Summe wertlos.\n");

    exit(2);
}

printf("\n  %-30s %6d Bytes  %5.1f KB\n", 'Summe', $summe, $summe / 1024);
printf("  %-30s %6d Bytes  %5.1f KB\n", 'Decke', DECKE, DECKE / 1024);

if ($summe > DECKE) {
    fwrite(
        STDERR,
        sprintf(
            "\nDas Immer-Gelesene ist um %d Bytes (%.1f KB) über der Decke.\n"
            . "Eine Regel hinzuzufügen heisst, eine wegzunehmen — oder eine Herleitung in die\n"
            . "Entscheidung zu verschieben, die sie trägt (Arbeitsmodell §4).\n"
            . "Die Decke anzuheben ist eine Entscheidung und wird in dieser Datei begründet.\n",
            $summe - DECKE,
            ($summe - DECKE) / 1024
        )
    );

    exit(1);
}

if ($summe < DECKE - 1024) {
    printf(
        "\n%.1f KB unter der Decke — sie darf in dieser Datei nachgezogen werden.\n",
        (DECKE - $summe) / 1024
    );

    exit(0);
}

printf("\nUnter der Decke, mit %d Bytes Luft.\n", DECKE - $summe);

exit(0);
