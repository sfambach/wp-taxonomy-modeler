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
 * | **D-134** (22.08.) | *«`relation_records` keys on a **path** … **Required by D-133's flattening**»* |
 * | **D-133** | trägt oben *«Überholt durch D-232»* |
 * | **D-232** (23.08.) | *«The branch decides where a value is stored, **not** the multiplicity. Supersedes D-133.»* |
 *
 * *Der Grund für die Spalte fiel am Tag nach ihrer Einführung. Die Spalte blieb. Gemessen am 2026-09-01
 * haben **183 von 183** Wertzeilen `path === relation_id` — die zusammengesetzte Form hat **keinen einzigen
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
 * ⚠️ *Gemessen am 2026-09-01: erst **108**, nach der symmetrischen Ausnahme **85**. Diese Zahl ist
 * die Schuld, nicht das Ziel — sie darf sinken und nie steigen. Wer sie anhebt, schreibt daneben,
 * warum.*
 *
 * ⚠️ **Der Sprung von 108 auf 85 ist kein Aufräumen, sondern eine genauere Frage.** *24 der
 * gemeldeten Verweise waren Nachfolgerinnen, die ihre Vorgängerin nennen — **in beiden Richtungen
 * dokumentiert**, also gerade der Beleg dafür, dass die Ablösung nachvollziehbar ist. Sie wurden
 * gemeldet, weil die Ausnahme nur die zurückgenommene Zeile übersprang, nicht die ablösende. Die 85
 * übrigen sind unverändert Berufungen ohne Gegenbeleg.*
 *
 * ⚠️ **Angehoben auf 86 am 2026-09-01, und hier steht warum** — *wie die Regel oben es verlangt.
 * [D-567](../../docs/NewConcept/90-decision-log.md) nennt `D-020`, `D-083` und `D-133`, **weil ihr
 * ganzer Inhalt die Erzählung dieser Kette ist**: es gibt keine einzelne Entscheidung, die die
 * heutigen Basistabellen festlegt, und das nachzuweisen heisst, die abgelösten Glieder zu nennen.
 * Genau der Fall, den der Kopf dieser Datei beschreibt: «mechanisch trennen lässt sich «als
 * Geschichte» nicht von «als Grund»».*
 * ⚠️ **Angehoben auf 87 am 2026-09-01, zweiter Grund.** *[D-569](../../docs/NewConcept/90-decision-log.md)
 * nennt `D-020`, **weil ihre Zurücknahme der Anlass der Entscheidung ist**: `AR-2` stand auf einer
 * zurückgenommenen Entscheidung, und genau das begründet, warum eine Messung als Grundlage taugt.
 * Die Zeile ohne diesen Verweis wäre unbelegt.*
 *
 * ⚠️ **Angehoben auf 88 am 2026-09-01, dritter Grund.** *[D-577](../../docs/NewConcept/90-decision-log.md)
 * nennt `D-133`, **weil dessen Flachklopfen der ganze Grund für den Pfad war**: «required by D-133's
 * flattening». Ohne den Verweis liesse sich nicht erklären, warum der Pfad je da war und warum er
 * ersatzlos fällt. Erzählung, nicht Berufung — der Fall, den der Kopf dieser Datei beschreibt.*
 *
 * ⚠️ **Auf 85 gesenkt am 2026-09-02, nachdem die Erkennung geschärft wurde.** *Vier Entscheidungen
 * galten fälschlich als zurückgenommen, weil ihr Text die Rücknahme einer **anderen** erzählt —
 * damit war auch jede Berufung auf sie falsch gezählt. **Die Decke war insoweit fiktiv.**
 * Rücknahmen: 30 gemeldet, **26 echt**.*
 * ⚠️ **Angehoben auf 86 am 2026-09-04, und hier steht warum.** *[D-606](../../docs/NewConcept/90-decision-log.md)
 * nennt `D-605`, **weil sie erzählt, dass sie auf ihr stand und nicht mehr darauf steht**: die Zeile
 * trug ursprünglich D-605 als Grund, D-605 wurde durch D-607 zurückgenommen, und der Verweis steht
 * jetzt in einem Satz, der genau das sagt. **Ohne ihn läse sich D-606, als habe sie nie einen Grund
 * gehabt.** Derselbe Fall, den der Kopf dieser Datei beschreibt: «mechanisch trennen lässt sich ‹als
 * Geschichte› nicht von ‹als Grund›».*
 *
 * ⚠️ **Angehoben auf 101 am 2026-09-11, und hier steht warum.** *[D-712](../../docs/NewConcept/90-decision-log.md)
 * hat das Einstellungsmodell von null neu entschieden und damit sieben Beschlüsse in einem Zug
 * abgelöst — `D-602`, `D-639`, `D-642`, `D-643`, `D-668`, `D-673`, `D-704`. **Fünfzehn geltende
 * Zeilen berufen sich auf sie** (`D-611`, `D-617`, `D-655`, `D-659`, `D-666`, `D-667`, `D-679`,
 * `D-681`, `D-682`, `D-685`, `D-690`, `D-697`, `D-700`, `D-705`, `D-707`), alle aus der Zeit, in der
 * die Einstellungen noch Datensätze und Kanten waren. Sie sind nicht falsch geworden, sondern
 * **Geschichte**: sie beschreiben den Bau, wie er heute steht, und der wird mit TASK-092 bis
 * TASK-096 umgebaut. Die Zeilen umzuschreiben hiesse, die Geschichte zu fälschen; die Decke bleibt
 * die Schuld, und sie sinkt, sobald der Umbau die alten Zeilen selbst ablöst. Sein Wort zum Anlass:
 * «übernimm das beschlossene für die modellseite ins konzept, und nicht so wie es aktuell ist.»*
 */
const HINGENOMMEN = 101;

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
    //
    // ⚠️ **Nur der Anfang der Entscheidungsspalte zählt, und das ist eine Korrektur vom 2026-09-02.**
    // *Vorher wurde die ganze Zeile durchsucht — und eine Entscheidung, die **die Rücknahme einer
    // anderen erzählt**, galt damit selbst als zurückgenommen. **Gemessen waren es vier:** `D-088`,
    // `D-373`, `D-457`, `D-532`. Bei `D-457` fiel es auf, weil ihr Text «superseded by D-453» über
    // **D-449** sagt. **Alle 26 echten Rücknahmen tragen die Markierung vorn**, das ist die
    // Schreibweise des Buches.*
    $anfang = mb_substr(trim(explode('|', $z)[3] ?? ''), 0, 120);

    if (
        str_contains($anfang, 'Überholt durch')
        || str_contains($anfang, 'Superseded by')
        || str_contains($anfang, 'superseded by')
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
        if (! str_contains($zeile, '[' . $alt . ']')) {
            continue;
        }

        // ⚠️ **Die Nachfolgerin darf ihre Vorgängerin nennen — sie muss, sonst wäre die Ablösung
        // nicht nachvollziehbar.** *Die Ausnahme oben griff nur in einer Richtung: die
        // zurückgenommene Zeile wurde übersprungen, die ablösende nicht. Am 2026-09-01 stieg die
        // Zahl deswegen von 108 auf 109, als `D-564` die von ihr abgelöste `D-342` nannte —
        // **ein Wächter, der die richtige Arbeit meldet, wird abgeschaltet.** Der Beleg ist
        // gegenseitig und deshalb prüfbar: die alte Zeile nennt die neue als ihren Nachfolger.*
        if (str_contains($entscheidungen[$alt] ?? '', '[' . $id . ']')) {
            continue;
        }

        $verweise[$id][] = $alt;
    }
}

echo 'geltende, die eine zitieren: ', count($verweise), "\n\n";

foreach ($verweise as $wer => $welche) {
    echo '  ', $wer, ' → ', implode(', ', $welche), "\n";
}

// ============================================================================
// Umgezogen am 2026-09-06: `supersession-check.php` las dieselbe Ablösung von
// der anderen Seite
// ============================================================================
//
// ⚠️ **Dieselbe Ablösung, zwei Richtungen** — *dort oben: wer beruft sich auf eine zurückgenommene
// Entscheidung. Hier: **nennt die zurückgenommene ihren Nachfolger, und zwar vorn.** Der zweite Lauf
// ist am 2026-09-06 gestrichen und seine Zusage hierher gezogen
// ([`waechter-bestand.md`](../../docs/pakete/modelltabellen/waechter-bestand.md), auf sein Wort
// «checks mein ja»). `PR-9`: umgezogen, nicht entschärft.*
//
// ⚠️ **Warum «vorn» und nicht «irgendwo».** *Gemessen am 2026-08-28: **25 von 28** überholten
// Entscheidungen sagten es erst irgendwo in 1400 Zeichen Prosa. Wer aus einem Docblock kommt —
// `D-133` wird 49-mal zitiert, `D-217` 59-mal — liest dann die ersetzte Regel und nicht den Hinweis.
// **Der Eigentümer hat genau das gemeldet: «jedes Mal, wenn Du auf eine Id referenzierst, kann ich
// die nicht lesen.»***

require __DIR__ . '/lib/supersessions.php';

$rows = taxmodDecisionRows($log);

$supersessionFehler = 0;

if (count($rows) < 100) {
    echo "\nNur " . count($rows) . " Entscheidungen erkannt — das Log wurde nicht richtig gelesen.\n";

    exit(1);
}

$ohneRueckverweis = [];
$beidseitig       = 0;
$zuSpaet          = [];

foreach (taxmodSupersessions($rows) as $victim => $killers) {
    $cells = explode(' | ', rtrim($rows[$victim], " |\n"));
    // ⚠️ *220 Zeichen: so weit liest jemand, der einer Id aus einem Docblock folgt, bevor er glaubt zu
    // wissen, was dort steht. Die Zahl ist gesetzt und nicht gemessen — sie ist eine Behauptung über
    // Leseverhalten, und sie steht hier, damit sie kritisierbar ist.*
    $head    = mb_substr($cells[2] ?? '', 0, 220);
    $upFront = false;

    foreach ($killers as $killer) {
        // Das Opfer muss seinen Nachfolger nennen — irgendwo in seiner Zeile.
        if (str_contains($rows[$victim], $killer)) {
            ++$beidseitig;
        } else {
            $ohneRueckverweis[$victim][$killer] = true;
        }

        if (str_contains($head, $killer)) {
            $upFront = true;
        }
    }

    if (! $upFront) {
        $zuSpaet[$victim] = array_keys($killers === [] ? [] : array_flip($killers));
    }
}

echo "\n== jede ersetzte Entscheidung nennt ihren Nachfolger ==\n";

foreach ($zuSpaet as $victim => $killers) {
    printf(
        "  SPAET %-7s nennt %s erst spaeter in der Zeile — wer aus einem Docblock kommt, liest die ersetzte Regel\n",
        $victim,
        implode(', ', $killers)
    );
}

foreach ($ohneRueckverweis as $victim => $killers) {
    printf("  FEHLT %-7s sagt nicht, dass %s sie ersetzt\n", $victim, implode(', ', array_keys($killers)));
}

if ($ohneRueckverweis === [] && $zuSpaet === []) {
    printf("  ok   %d Ersetzungen, jede beidseitig verzeichnet und im ersten Satz genannt\n", $beidseitig);
} else {
    $supersessionFehler = count($ohneRueckverweis) + count($zuSpaet);

    printf(
        "\n%d beidseitig verzeichnet, %d einseitig, %d erst spaet genannt — diese lesen sich wie gueltige Entscheidungen.\n",
        $beidseitig,
        count($ohneRueckverweis),
        count($zuSpaet)
    );
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

    exit($supersessionFehler === 0 ? 0 : 1);
}

echo "Unverändert bei {$anzahl}.\n";

exit($supersessionFehler === 0 ? 0 : 1);
