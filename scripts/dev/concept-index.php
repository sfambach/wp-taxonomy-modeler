<?php declare(strict_types=1);

/**
 * Generate a one-line map of every decision and every open question.
 *
 * ⚠️ **Why a map and not a rewrite.** The owner: *do you want to make the documentation smaller and
 * compress it — I think it is now too big for you to grasp all aspects.* **Measured, compressing would
 * hide the problem rather than fix it**: of 102 open questions, **86 are genuinely open** — the file is
 * long because that much is undecided, not because it is padded.
 *
 * ⚠️ **And the decision log must not be rewritten at all.** It is the authority (`PR-3`): *nothing is
 * decided until it is in the log*. **A condensed decision is a new decision nobody agreed to** — which
 * is the road the previous 82 KB rule set went down, and the reason it was thrown away.
 *
 * ⚠️ **What is actually missing is a way to see all of it at once.** 432 decisions in 527 KB cannot be
 * read; one line each can. *This file is derived, never authoritative, and regenerated rather than
 * edited — so it cannot drift into disagreeing with its sources without the next run saying so.*
 *
 * ⚠️ *It writes `99-index.md` and prints nothing but the counts, so it can also run as a check: a
 * generated file that nobody regenerates is worse than none.*
 *
 * Usage: php scripts/dev/concept-index.php
 *
 * @see docs/NewConcept/90-decision-log.md
 */

$root = __DIR__ . '/../../docs/NewConcept/';
$out  = $root . '99-index.md';

/** The bold lead sentence of an entry, which is the one line its author wrote to be read first. */
function lead(string $text, int $keep = 150): string
{
    // The house style opens every entry with **…** — that is the summary, already written.
    if (preg_match('/\*\*(.+?)\*\*/s', $text, $m)) {
        $text = $m[1];
    }

    $text = trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
    $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text);

    return mb_strlen($text) <= $keep ? $text : mb_substr($text, 0, $keep - 1) . '…';
}

// ── Decisions ──
require __DIR__ . '/lib/supersessions.php';

/**
 * Die Kategorien einer Entscheidung — aus der Dokumentspalte, die es schon gibt.
 *
 * @return list<string>
 */
function taxmodCategories(string $cell): array
{
    preg_match_all('/\[[^\]]+\]\((\d{2})-([a-z0-9-]+)\.md\)/', $cell, $m, PREG_SET_ORDER);

    $out = [];

    foreach ($m as $one) {
        // ⚠️ *Nur die Konzeptdokumente 00–70 sind Sachgebiete. `90` bis `99` sind das Log, die
        // Fragen, die Arbeitsliste und der Index selbst — sie sagen nichts darüber, **worum** es
        // geht, und als Kategorie würden sie die Liste verwässern.*
        if ((int) $one[1] > 70) {
            continue;
        }

        $out[$one[1] . '-' . $one[2]] = true;
    }

    return array_keys($out);
}

// ⚠️ **Dieselbe Stelle wie `supersession-check.php`** (`lib/supersessions.php`). *Vorher hatte
// jede ihre eigene Erkennung: die Prüfung an den Verben, dieser Index an zwei deutschen
// Wendungen. **Am 2026-08-28 war die Prüfung grün und hier stand dieselbe Entscheidung als
// `agreed`** — und der Eigentümer schloss daraus zu Recht, dass er der Datei nicht trauen kann.*
$overtakenBy = taxmodSupersessions(taxmodDecisionRows($root . '90-decision-log.md'));

$rows = [];

foreach (file($root . '90-decision-log.md') as $line) {
    if (! preg_match('/^\| (D-\d+) \| (\d{4}-\d{2}-\d{2}) \|/', $line, $m)) {
        continue;
    }

    $cells  = explode(' | ', rtrim($line, " |\r\n"));
    $status = trim($cells[count($cells) - 3] ?? '?');

    $rows[] = [
        'id'     => $m[1],
        'date'   => $m[2],
        'lead'   => lead($cells[2] ?? ''),
        'status' => str_contains($status, 'superseded') ? 'superseded' : trim($status, '`'),
        'over'   => implode(', ', $overtakenBy[$m[1]] ?? []),
        // ⚠️ **Die Kategorie stand schon da und war nur nicht lesbar.** *Der Eigentümer fragte,
        // ob wir Kategorien einführen sollten, «damit man filtern kann — für Dich halt auch für
        // die Suche». **Gemessen: 485 von 486 Zeilen nennen ihr Dokument, und das ist die
        // Kategorie** — Domänenkern 216, Renderer 192, Interaktion 98, Persistenz 90. Mehrere pro
        // Entscheidung gibt es auch schon: 231 Zeilen nennen zwei, 25 nennen drei. *Eine zweite
        // Reihe einzuführen wäre dieselbe Tatsache zweimal und 486 Zeilen Handarbeit.*
        'cats'   => taxmodCategories($cells[count($cells) - 2] ?? ''),
    ];
}

// ── Open questions ──
$questions = [];
$current   = null;

foreach (file($root . '91-open-questions.md') as $line) {
    if (preg_match('/^## (OQ-\d+)\s*—?\s*(.*)$/u', $line, $m)) {
        $current = $m[1];

        $questions[$current] = ['title' => lead($m[2], 120), 'status' => '?'];

        continue;
    }

    if ($current !== null && $questions[$current]['status'] === '?' && preg_match('/\*Status:\*\s*(.+)$/u', $line, $s)) {
        $said = strtolower(strip_tags($s[1]));

        $questions[$current]['status'] = str_contains($said, 'open')
            && ! str_contains($said, 'answered')
            && ! str_contains($said, 'closed')
                ? 'offen'
                : 'beantwortet';
    }
}

$stillOpen = count(array_filter($questions, static fn (array $q): bool => $q['status'] === 'offen'));

// ── Write it ──
$md = "# 99 · Index — eine Zeile pro Entscheidung und pro Frage\n\n"
    . "⚠️ **Erzeugt, nicht geschrieben.** `php scripts/dev/concept-index.php` baut diese Datei aus\n"
    . "[`90-decision-log.md`](90-decision-log.md) und [`91-open-questions.md`](91-open-questions.md).\n"
    . "**Sie ist nie die Autorität** — `PR-3` sagt, entschieden ist, was im Log steht, und eine\n"
    . "verkürzte Entscheidung wäre eine neue, der niemand zugestimmt hat. *Hier steht, **wo** etwas\n"
    . "steht, damit man 432 Entscheidungen überblicken kann, ohne 527 KB zu lesen.*\n\n"
    . sprintf(
        "| | |\n|---|---|\n| Entscheidungen | **%d**, davon **%d** ersetzt oder teilweise überholt |\n| Offene Fragen | **%d**, davon **%d** noch offen |\n\n",
        count($rows),
        count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'superseded' || $r['over'] !== '')),
        count($questions),
        $stillOpen
    )
    . "## Offene Fragen\n\n⚠️ *Zuerst, weil sie die Arbeit blockieren und Entscheidungen sie nicht.*\n\n"
    . "| Frage | Stand | Worum es geht |\n|---|---|---|\n";

foreach ($questions as $id => $q) {
    $md .= sprintf(
        "| [%s](91-open-questions.md) | %s | %s |\n",
        $id,
        $q['status'] === 'offen' ? '**offen**' : 'beantwortet',
        $q['title'] === '' ? '—' : $q['title']
    );
}

// ── Nach Sachgebiet, damit man filtern kann ──────────────────────────────────
//
// ⚠️ **Der Eigentümer fragte nach Kategorien — «damit man filtern kann, für Dich halt auch für die
// Suche» — und die Antwort war, dass es sie schon gibt.** *Gemessen: **485 von 486** Zeilen nennen
// ihr Dokument, und das **ist** das Sachgebiet. Auch mehrere pro Entscheidung, was er ausdrücklich
// wollte: 231 Zeilen nennen zwei, 25 nennen drei. **Eine zweite Reihe einzuführen wäre dieselbe
// Tatsache zweimal und 486 Zeilen Handarbeit.** Was fehlte, war nur: sichtbar machen.*
$byCategory = [];

foreach ($rows as $r) {
    foreach ($r['cats'] as $cat) {
        $byCategory[$cat][] = $r['id'];
    }
}

uasort($byCategory, static fn (array $a, array $b): int => count($b) <=> count($a));

$md .= "\n## Entscheidungen nach Sachgebiet\n\n"
    . "⚠️ *Kein neues Merkmal — das ist die Dokumentspalte jeder Log-Zeile, nur lesbar gemacht.\n"
    . "Eine Entscheidung steht in mehreren Gebieten, wenn sie mehrere betrifft.*\n\n"
    . "| Sachgebiet | Entscheidungen | davon überholt |\n|---|---|---|\n";

// ⚠️ **Nur die Zahl, nicht die Ids.** *Die erste Fassung listete alle 216 Ids des Domänenkerns in eine
// Zelle — der Index wuchs von 81 auf 116 KB und die Übersicht war unlesbarer als das, was sie
// übersichtlich machen sollte. **Wer die Zeilen eines Gebiets sucht, filtert die Tabelle darunter nach
// dem Namen des Gebiets**; dafür steht er dort in jeder Zeile.*
foreach ($byCategory as $cat => $ids) {
    $ueberholt = 0;

    foreach ($rows as $r) {
        if (in_array($r['id'], $ids, true) && $r['over'] !== '') {
            ++$ueberholt;
        }
    }

    $md .= sprintf(
        "| [%s](%s.md) | **%d** | %s |\n",
        $cat,
        $cat,
        count($ids),
        $ueberholt === 0 ? '—' : (string) $ueberholt
    );
}

$md .= "\n## Entscheidungen\n\n⚠️ *Eine überholte Entscheidung steht hier mit ihrem Nachfolger, weil sie\nsich sonst wie eine gültige liest — der Fehler, der an einem Tag drei falsche Antworten kostete.*\n\n"
    . "| Nr. | Datum | Stand | Sachgebiet | Worum es geht |\n|---|---|---|---|---|\n";

foreach ($rows as $r) {
    // ⚠️ *«Ersetzt durch» und nicht bloss «ersetzt»: wer die Zeile liest, will sofort wissen, wo
    // die geltende Fassung steht. Ein Statuswort schickt ihn zurück ins 682-KB-Log.*
    $stand = $r['over'] !== ''
        ? '⚠️ ersetzt durch ' . $r['over']
        : ($r['status'] === 'superseded' ? '⚠️ ersetzt' : $r['status']);

    $md .= sprintf(
        "| [%s](90-decision-log.md) | %s | %s | %s | %s |\n",
        $r['id'],
        $r['date'],
        $stand,
        $r['cats'] === [] ? '⚠️ **keins**' : implode(', ', $r['cats']),
        $r['lead']
    );
}

if (file_put_contents($out, $md) === false) {
    echo "Konnte {$out} nicht schreiben.\n";

    exit(1);
}

printf(
    "%d Entscheidungen, %d Fragen (%d offen) — %s, %d KB\n",
    count($rows),
    count($questions),
    $stillOpen,
    basename($out),
    (int) (strlen($md) / 1024)
);

if (count($rows) < 100 || $questions === []) {
    echo "Zu wenig erkannt — die Quellen wurden nicht richtig gelesen.\n";

    exit(1);
}
