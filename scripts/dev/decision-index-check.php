<?php declare(strict_types=1);

/**
 * Sagt das Verzeichnis noch, **was heute gilt**?
 *
 * ⚠️ **Der Anlass ist gemessen, nicht befürchtet.** *Am 2026-09-05 wurden an einem Abend dreimal
 * überholte Entscheidungen als geltend zitiert — der Installationsbildschirm, die sprachneutrale
 * Zeile, die Rollenfrage. **Jedes Mal wurde eine Entscheidung gefunden, nur die falsche**
 * ([D-645](../../docs/NewConcept/90-decision-log.md)). Das Verzeichnis ist die Antwort darauf, und
 * ein Verzeichnis, das selbst veraltet, ist genau der Schaden noch einmal — nur mit Anspruch.*
 *
 * Drei Zusagen, und jede ist eine Ja-Nein-Frage:
 *
 * 1. **Jede genannte `D-<nnn>` gibt es** — sonst zeigt das Verzeichnis ins Leere.
 * 2. **Keine als *gilt heute* genannte ist anderswo als überholt markiert** — das ist der Fehler,
 *    gegen den es gebaut wurde.
 * 3. **Keine Entscheidung, die eine andere ausdrücklich berichtigt, fehlt darin** — sonst wächst
 *    genau die Lücke wieder zu, in die man hineinfällt.
 *
 * ⚠️ **Wer überholt wen, weiss `lib/supersessions.php` — und diese Prüfung schreibt es nicht neu.**
 * *Dort steht, warum: zwei Kopien derselben Regel hatten schon einmal eine grüne Prüfung neben einem
 * falschen Index stehen. Was hier dazukommt, sind **andere Verben** — «berichtigt», «löst … ab»,
 * «verwirft», «dreht … um», «nimmt … zurück» —, die jene Stelle bewusst nicht kennt, weil sie eine
 * schwächere Aussage machen: sie berichtigen einen **Teil**. Deshalb zählen sie hier **nur** für
 * Zusage 3 (muss genannt sein) und nie für Zusage 2 (gilt nicht mehr) — Anwesenheit ist billig und
 * immer richtig, «gilt nicht mehr» braucht die strenge Lesart.*
 *
 * @see docs/NewConcept/03-entscheidungsverzeichnis.md
 * @see docs/NewConcept/90-decision-log.md
 */

chdir(__DIR__ . '/../..');

require __DIR__ . '/lib/supersessions.php';

const VERZEICHNIS = 'docs/NewConcept/03-entscheidungsverzeichnis.md';
const BUCH        = 'docs/NewConcept/90-decision-log.md';

if (! is_file(VERZEICHNIS)) {
    fwrite(STDERR, "Das Verzeichnis fehlt: " . VERZEICHNIS . "\n");

    exit(2);
}

$rows = taxmodDecisionRows(BUCH);

if ($rows === []) {
    fwrite(STDERR, "Keine einzige Entscheidung im Buch erkannt — das Muster passt nicht mehr.\n");

    exit(2);
}

$ueberholt = taxmodSupersessions($rows);

/**
 * Wer berichtigt wen — **die Verben, die `lib/supersessions.php` nicht kennt**.
 *
 * ⚠️ *Dieselbe Vorsicht wie dort: steht **vor** dem Verb schon eine Id, ist die das Subjekt und der
 * Treffer gehört einer anderen Zeile. Und «berichtigt durch» ist ein Rückverweis, kein Anspruch.*
 *
 * @param  array<string, string>       $rows
 * @return array<string, list<string>> berichtigende Entscheidung ⇒ die von ihr berichtigten
 */
function taxmodBerichtigungen(array $rows): array
{
    $aus = [];

    foreach ($rows as $id => $line) {
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $line) ?? $line;

        if (! preg_match_all(
            '/(?<vor>[^.|]{0,40})(?<verb>berichtigt|verwirft|nimmt|dreht|löst)(?<luecke>[^.|,]{0,60}?)(?<opfer>D-\d+)(?<nach>[^.|]{0,40})/u',
            $text,
            $treffer,
            PREG_SET_ORDER
        )) {
            continue;
        }

        foreach ($treffer as $t) {
            if (preg_match('/D-\d+/', $t['vor']) || preg_match('/\b(?:ist|wurde|sind|wurden)\s*$/u', $t['vor'])) {
                continue;
            }

            if (preg_match('/\bdurch\b/u', $t['luecke'])) {
                continue;
            }

            // ⚠️ *Diese drei Verben sagen ohne ihr zweites Wort nichts: «löst … **ein**» vollzieht,
            // «löst … **ab**» berichtigt. Ohne die Nachsilbe wären es Dutzende Fehlalarme.*
            $nachsilbe = [
                'löst'  => '/\bab\b/u',
                'dreht' => '/\bum\b/u',
                'nimmt' => '/zurück/u',
            ];

            if (isset($nachsilbe[$t['verb']]) && ! preg_match($nachsilbe[$t['verb']], $t['nach'])) {
                continue;
            }

            $opfer = $t['opfer'];

            if ($opfer === $id || ! isset($rows[$opfer])) {
                continue;
            }

            $aus[$id][$opfer] = true;
        }
    }

    $fertig = [];

    foreach ($aus as $id => $opfer) {
        $fertig[$id] = array_keys($opfer);
    }

    return $fertig;
}

$berichtigt = taxmodBerichtigungen($rows);

// Wer eine andere Entscheidung ablöst oder berichtigt — die müssen im Verzeichnis stehen.
$nachfolger = array_keys($berichtigt);

foreach ($ueberholt as $opfer => $killer) {
    foreach ($killer as $k) {
        $nachfolger[] = $k;
    }
}

$nachfolger = array_values(array_unique($nachfolger));
sort($nachfolger);

$text     = (string) file_get_contents(VERZEICHNIS);
$zeilen   = explode("\n", $text);
$genannt  = [];
$geltend  = [];

preg_match_all('/D-\d+/', $text, $alle);

foreach ($alle[0] as $id) {
    $genannt[$id] = true;
}

// ⚠️ *«gilt heute» ist die **zweite** Spalte einer dreispaltigen Zeile. Die Einstellungsbereich «Unklar» hat zwei
// Spalten und wird darum nicht gelesen — dort steht absichtlich keine geltende Entscheidung.*
foreach ($zeilen as $z) {
    $z = trim($z);

    if ($z === '' || $z[0] !== '|' || str_contains($z, '---')) {
        continue;
    }

    $zellen = array_map('trim', explode('|', trim($z, '| ')));

    if (count($zellen) !== 3) {
        continue;
    }

    // Durchgestrichenes ist ausdrücklich nicht geltend, auch nicht in der zweiten Spalte.
    $spalte = preg_replace('/~~.*?~~/u', '', $zellen[1]) ?? $zellen[1];

    preg_match_all('/D-\d+/', $spalte, $ids);

    foreach ($ids[0] as $id) {
        $geltend[$id] = $zellen[0];
    }
}

$fehler = 0;

// 1 · Zeigt das Verzeichnis irgendwohin, wo nichts steht?
foreach (array_keys($genannt) as $id) {
    if (! isset($rows[$id])) {
        fwrite(STDERR, "Unbekannte Entscheidung im Verzeichnis: {$id}\n");
        $fehler++;
    }
}

// 2 · Nennt es eine überholte als geltend?
foreach ($geltend as $id => $frage) {
    if (isset($ueberholt[$id])) {
        fwrite(STDERR, "«{$frage}» nennt {$id} als geltend — überholt durch " . implode(', ', $ueberholt[$id]) . "\n");
        $fehler++;

        continue;
    }

    $stand = $rows[$id] ?? '';
    $zelle = array_map('trim', explode('|', trim($stand, "|\r\n ")));
    $stand = $zelle[3] ?? '';

    if (preg_match('/superseded|überholt|veraltet|REJECTED/ui', $stand)) {
        fwrite(STDERR, "«{$frage}» nennt {$id} als geltend — das Buch führt sie als: {$stand}\n");
        $fehler++;
    }
}

// 3 · Fehlt eine Entscheidung, die eine andere berichtigt?
$fehlend = [];

foreach ($nachfolger as $id) {
    if (! isset($genannt[$id])) {
        $fehlend[] = $id;
    }
}

if ($fehlend !== []) {
    fwrite(STDERR, "Berichtigende Entscheidungen fehlen im Verzeichnis: " . implode(' ', $fehlend) . "\n");
    $fehler += count($fehlend);
}

printf(
    "Verzeichnis: %d Entscheidungen genannt, %d davon als geltend · Buch: %d Zeilen, %d Ablösungen, %d Berichtigungen\n",
    count($genannt),
    count($geltend),
    count($rows),
    count($ueberholt),
    count($berichtigt)
);

if ($fehler > 0) {
    fwrite(STDERR, "{$fehler} Befund(e).\n");

    exit(1);
}

echo "Das Verzeichnis sagt, was gilt.\n";

exit(0);
