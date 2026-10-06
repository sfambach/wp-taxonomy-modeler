<?php declare(strict_types=1);

/**
 * Trägt eine als bestätigt markierte Entscheidung wirklich einen Satz des Eigentümers?
 *
 *     php scripts/dev/confirmed-quote-check.php
 *
 * ⚠️ **Dieser Wächter macht die älteste unprüfbare Regel des Projekts prüfbar.** *`PR-5` verlangte,
 * das Konzept werde «zuerst aus den Aussagen des Eigentümers» geschrieben — und **niemand konnte
 * nachsehen, ob das geschah.** Gemessen am 2026-09-01: in den **ersten hundert** Entscheidungen ist
 * **3 %** auf einen Satz von ihm zurückführbar, im letzten Hundert **90 %**. Die Regel stand die
 * ganze Zeit da.*
 *
 * ⚠️ **Was ihn prüfbar macht, ist nicht die Regel, sondern der Status** ([`docs/arbeitsmodell.md`](../../docs/arbeitsmodell.md) §3.1):
 * *`CONFIRMED` heisst «er hat es gesagt», `INFERRED` heisst «ich habe es geschlossen». **Damit ist die
 * Frage endlich eine Ja-Nein-Frage:** steht in einer bestätigten Zeile ein Zitat, oder nicht?*
 *
 * ⚠️ **Der Wächter urteilt nicht über den Inhalt, nur über das Vorhandensein.** *Ein Zitat kann
 * falsch verstanden sein — das fängt er nicht. **Er fängt den Fall, der hier neunmal vorkam: eine
 * Entscheidung als seine ausgegeben, die meine war.** Der teuerste davon kostete fünf Worte Antwort:
 * «das waren nicht meine argumente sondern deine».*
 *
 * ⚠️ *Der Altbestand ist ausgenommen: er trägt `agreed` und keinen der neuen Statuswerte, und nach
 * §6.1 ist er `LEGACY`, bis er geprüft wird. **Der Wächter greift ab der ersten Zeile, die sich
 * ausdrücklich «bestätigt» nennt.***
 *
 * @see docs/arbeitsmodell.md
 */

$log = dirname(__DIR__, 2) . '/docs/NewConcept/90-decision-log.md';

if (! is_readable($log)) {
    fwrite(STDERR, "Kein Entscheidungsbuch unter {$log}\n");

    exit(2);
}

/**
 * Wortlaute, die eine Zeile als bestätigt ausweisen.
 *
 * ⚠️ *Zweisprachig, weil das Buch so gewachsen ist. **Eine vierte Schreibweise fiele hier durch** —
 * deshalb prüft der Lauf unten, dass überhaupt welche gefunden wurden.*
 */
const ALS_BESTAETIGT = ['CONFIRMED', 'vom Eigentümer bestätigt', 'vom Eigentümer entschieden'];

/**
 * ⚠️ **Der erste Entwurf hatte zwei Fehlalarme, und beide waren lehrreich.**
 *
 * *Er suchte die ganze Zeile statt der Statusspalte — so schlug `D-095` an, deren **Text** die
 * Wendung enthält, während ihr Status «half superseded» lautet. Und er kannte nur Guillemets als
 * Zitatform: **`D-166`, `D-175` und `D-196` tragen seine Worte kursiv**, weil das frühe Buch so
 * zitierte — «on input we should avoid needless errors».*
 *
 * ⚠️ *Beide Fehler zeigten **richtige Arbeit** als Verstoss an, und ein solcher Wächter wird
 * abgeschaltet. Die Lösung ist nicht, die Erkennung aufzuweichen, sondern den Umfang zu schärfen:
 * **geprüft wird die Statusspalte, und nur der neue Wortlaut.** Der Altbestand ist nach §6.1 des
 * Arbeitsmodells `LEGACY`, bis er geprüft wird — er ist nicht die Aufgabe dieses Laufs.*
 */
const ALTER_WORTLAUT = 'confirmed by the owner';

/**
 * Was als Zitat zählt.
 *
 * ⚠️ *Guillemets sind die Form, in der dieses Buch ihn zitiert. Kursiv allein genügt nicht — damit
 * wird auch meine Herleitung gesetzt.*
 */
function traegtZitat(string $zeile): bool
{
    return preg_match('/«[^»]{12,}»/u', $zeile) === 1;
}

$zeilen = file($log);

if ($zeilen === false) {
    fwrite(STDERR, "Nicht lesbar.\n");

    exit(2);
}

$bestaetigt = 0;
$ohneZitat  = [];
$altbestand = [];

foreach ($zeilen as $z) {
    if (! preg_match('/^\| (D-\d+) \|/', $z, $t)) {
        continue;
    }

    // Nur die Statusspalte — nicht die ganze Zeile. Siehe die Anmerkung bei ALTER_WORTLAUT.
    $status = explode('|', $z)[4] ?? '';

    if (str_contains($status, ALTER_WORTLAUT)) {
        $altbestand[] = $t[1];

        continue;
    }

    $istBestaetigt = false;

    foreach (ALS_BESTAETIGT as $wortlaut) {
        if (str_contains($status, $wortlaut)) {
            $istBestaetigt = true;
        }
    }

    if (! $istBestaetigt) {
        continue;
    }

    ++$bestaetigt;

    if (! traegtZitat($z)) {
        $ohneZitat[] = $t[1];
    }
}

printf("Als bestätigt markierte Entscheidungen: %d\n", $bestaetigt);

if ($altbestand !== []) {
    printf(
        "Im alten Wortlaut, nach §6.1 LEGACY und hier nicht geprüft: %d (%s)\n",
        count($altbestand),
        implode(', ', array_slice($altbestand, 0, 6)) . (count($altbestand) > 6 ? ' …' : '')
    );
}

// ⚠️ **Der Gegenfall, und ohne ihn wiegt der Lauf nichts.** *Findet die Markierung nichts, ist jede
// Zählung null und alles grün — auch wenn jede bestätigte Zeile ohne Zitat dastünde.*
if ($bestaetigt === 0) {
    fwrite(STDERR, "Keine einzige als bestätigt markierte Entscheidung gefunden — die Wortlaute passen nicht mehr.\n");

    exit(2);
}

if ($ohneZitat === []) {
    echo "Jede davon trägt einen Satz des Eigentümers.\n";

    exit(0);
}

fwrite(
    STDERR,
    "\nAls bestätigt markiert, aber ohne einen Satz von ihm:\n"
    . '  ' . implode(', ', $ohneZitat) . "\n\n"
    . "Entweder sein Zitat in die Zeile schreiben — oder den Status auf `INFERRED` setzen\n"
    . "und ihn entscheiden lassen. Eine Herleitung des Assistenten ist keine Bestätigung.\n"
);

exit(1);
