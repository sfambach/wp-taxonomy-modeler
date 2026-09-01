<?php declare(strict_types=1);

/**
 * Ändert sich ein Modelldokument, ohne dass ein Grund als Entscheidung dasteht?
 *
 *     php scripts/dev/concept-drift-check.php              Arbeitskopie gegen HEAD
 *     php scripts/dev/concept-drift-check.php --commit=abc  ein bestimmter Commit
 *
 * ⚠️ **Dieser Wächter gehört zu einer Regel des Eigentümers, und die Regel ersetzt einen Begriff, der
 * von mir kam.** *Er sagte am 2026-09-01: «D-338 beruht auf einem Missverständnis, ich wollte nie
 * Sachen locken … somit gibt es kein Lock, es gibt nur Konzept ist fertig und kann jetzt umgesetzt
 * werden. Oder Konzept ist veraltet und wird durch neues ersetzt.» Dazu: **«Modelle die beschlossen
 * sind nicht einfach ohne Grund aufgeweicht werden.»***
 *
 * ⚠️ **Der Tell, dass «locked» falsch war, stand in der Entscheidung selbst**: *zwei Sätze nach
 * «is `locked`» musste dort «locked is not frozen» stehen — ein Begriff, der sofort eine Ausnahme
 * braucht, war der falsche Begriff. Die brauchbare Hälfte von `PR-2` war nie das Lock, sondern
 * «never a quiet edit to the concept» ([D-222](../../docs/NewConcept/90-decision-log.md)).*
 *
 * ⚠️ **Was der Wächter durchlässt, ist an der Geschichte gemessen und nicht geraten.** *Über die
 * letzten 80 Commits änderten **12** ein Modelldokument, davon **einer** ohne das Entscheidungsbuch
 * zu berühren — `d2da9f0`. Dessen neue Zeilen **nennen** `D-183` und `D-522`: die Entscheidung war
 * einen Commit früher gefallen, nur der Code kam nach. **Deshalb genügt eine der beiden Belege:**
 * das Buch wird mitgeändert, **oder** die neuen Zeilen nennen eine Entscheidung, die es gibt. Ohne
 * die zweite Hälfte hätte der Wächter im einzigen historischen Fall falsch gemeldet — und ein
 * Wächter, der richtige Arbeit anzeigt, wird abgeschaltet.*
 *
 * ⚠️ **Der Umfang ist eng, und das ist Absicht.** *Bewacht sind die **Modelldokumente**. Nicht
 * bewacht sind das Entscheidungsbuch, die offenen Fragen, die Arbeitsliste und alles Erzeugte — dort
 * ist Änderung der Normalfall und nicht das Aufweichen eines beschlossenen Modells.*
 *
 * @see docs/NewConcept/90-decision-log.md
 */

/**
 * Die Dokumente, die das Modell beschreiben.
 *
 * ⚠️ *`90` und `91` fehlen absichtlich: das Buch **ist** der Grund, und eine offene Frage ist noch
 * kein Modell. `97` fehlt, weil die Arbeitsliste sich bei jeder Zeile ändert. `02-rules-index` fehlt,
 * weil es erzeugt wird.*
 */
const MODELLDOKUMENTE = [
    '00-vision-and-scope.md',
    '01-standard-tree.md',
    '01-glossary.md',
    '02-field-and-setting.md',
    '10-domain-core.md',
    '20-interaction.md',
    '30-renderer.md',
    '40-i18n.md',
    '50-wordpress-persistence.md',
    '60-calculation.md',
    '70-migration.md',
];

const BUCH = '90-decision-log.md';

$commit = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--commit=')) {
        $commit = substr($arg, 9);
    }
}

/** Ein git-Aufruf, dessen Fehlschlag nicht als leeres Ergebnis durchgeht. */
function git(string $argumente): string
{
    $befehl = 'git ' . $argumente . ' 2>&1';

    $ausgabe = [];
    $ausgang = 0;

    exec($befehl, $ausgabe, $ausgang);

    if ($ausgang !== 0) {
        fwrite(STDERR, "git schlug fehl: {$befehl}\n" . implode("\n", $ausgabe) . "\n");

        exit(2);
    }

    return implode("\n", $ausgabe);
}

chdir(dirname(__DIR__, 2));

// Welche Dateien haben sich geändert, und was sind die neuen Zeilen darin?
if ($commit === null) {
    $geaendert = git('diff HEAD --name-only');
    $diffFuer  = static fn (string $pfad): string => git('diff HEAD -- ' . escapeshellarg($pfad));
    $woher     = 'Arbeitskopie gegen HEAD';
} else {
    $geaendert = git('show --name-only --format= ' . escapeshellarg($commit));
    $diffFuer  = static fn (string $pfad): string => git('show ' . escapeshellarg($commit) . ' -- ' . escapeshellarg($pfad));
    $woher     = 'Commit ' . $commit;
}

$dateien = array_filter(array_map('trim', explode("\n", $geaendert)));

$buchGeaendert = false;

foreach ($dateien as $d) {
    if (str_contains($d, BUCH)) {
        $buchGeaendert = true;
    }
}

/** @var list<string> Modelldokumente, die sich geändert haben */
$betroffen = [];

foreach ($dateien as $d) {
    foreach (MODELLDOKUMENTE as $m) {
        if (str_ends_with($d, '/' . $m) || $d === $m) {
            $betroffen[] = $d;
        }
    }
}

$betroffen = array_values(array_unique($betroffen));

echo "Geprüft: {$woher}\n";
printf("Geänderte Modelldokumente: %d%s\n", count($betroffen), $buchGeaendert ? ' · das Entscheidungsbuch ist mitgeändert' : '');

if ($betroffen === []) {
    echo "Kein Modelldokument berührt — nichts zu prüfen.\n";

    exit(0);
}

if ($buchGeaendert) {
    echo "Der Grund steht im Entscheidungsbuch derselben Änderung.\n";

    exit(0);
}

// Zweiter Beleg: nennen die neuen Zeilen eine Entscheidung, die es gibt?
$vorhandene = [];

foreach (file('docs/NewConcept/' . BUCH) ?: [] as $z) {
    if (preg_match('/^\| (D-\d+) \|/', $z, $t)) {
        $vorhandene[$t[1]] = true;
    }
}

if ($vorhandene === []) {
    fwrite(STDERR, "Keine einzige Entscheidung im Buch erkannt — das Muster passt nicht mehr.\n");

    exit(2);
}

$ohneGrund = [];

foreach ($betroffen as $pfad) {
    $diff = $diffFuer($pfad);

    $genannt = [];

    foreach (explode("\n", $diff) as $z) {
        // Nur hinzugefügte Zeilen; der Kopf `+++` ist keine.
        if (! str_starts_with($z, '+') || str_starts_with($z, '+++')) {
            continue;
        }

        if (preg_match_all('/\bD-\d+\b/', $z, $t)) {
            foreach ($t[0] as $id) {
                if (isset($vorhandene[$id])) {
                    $genannt[$id] = true;
                }
            }
        }
    }

    if ($genannt === []) {
        $ohneGrund[] = $pfad;
    } else {
        printf("  %-46s nennt %s\n", basename($pfad), implode(', ', array_keys($genannt)));
    }
}

if ($ohneGrund === []) {
    echo "Jede Änderung nennt eine vorhandene Entscheidung.\n";

    exit(0);
}

fwrite(
    STDERR,
    "\nEin beschlossenes Modell wird hier ohne Grund geändert:\n"
    . '  ' . implode("\n  ", $ohneGrund) . "\n\n"
    . "Ein Modelldokument ändert sich nur mit einem Grund, der als Entscheidung dasteht.\n"
    . "Zwei Wege, beide zulässig:\n"
    . "  · die Entscheidung in derselben Änderung ins Entscheidungsbuch schreiben, oder\n"
    . "  · in den neuen Zeilen die vorhandene Entscheidung nennen, aus der die Änderung folgt.\n"
);

exit(1);
