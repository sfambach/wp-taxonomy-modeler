<?php declare(strict_types=1);

/**
 * Does every open question that a decision claims to answer say so?
 *
 * ⚠️ **The measurement that made this necessary, 2026-08-26.** The owner: *the documentation is now too
 * big for you to grasp all aspects — do you want to compress it?* **Measured, the size was not the
 * problem and neither was redundancy:** the concept documents share only **8 %** of their ten-word
 * phrases with the log, and of 102 questions **86 read as open**. *The problem was mismarking:* **68 of
 * those 86 are named as answered by a decision** and still say `Status: open`. **So the real number of
 * untouched questions is 18, not 86** — and nobody could see that.
 *
 * ⚠️ **It asks for a cross-reference, not a closed status, and the difference is deliberate.** The log's
 * wording is often partial — *«settles the **hard half** of OQ-001», «answers the **shape** of
 * OQ-089»* — so marking those questions closed would be **me** deciding, which `PR-3` forbids. *What
 * the note has to say is who claims it and that the reading is still to be done.*
 *
 * ⚠️ *Same shape as `supersession-check.php`, one file over: a document that cannot say it has been
 * overtaken will be read as current, and `PR-10`'s «look it up» does not help against a page that lies
 * quietly.*
 *
 * Usage: php scripts/dev/question-symmetry-check.php
 *
 * @see docs/NewConcept/91-open-questions.md
 */

$root = __DIR__ . '/../../docs/NewConcept/';

$log       = file_get_contents($root . '90-decision-log.md');
$questions = file($root . '91-open-questions.md');

if ($log === false || $questions === []) {
    echo "Die Quellen sind nicht lesbar.\n";

    exit(1);
}

// ── Who claims to have answered what ──
$claims = [];

foreach (explode("\n", $log) as $line) {
    if (! preg_match('/^\| (D-\d+) \|/', $line, $row)) {
        continue;
    }

    if (! preg_match_all('/(closes|answers|settles)[^.|]{0,90}?(OQ-\d+)/i', $line, $hit, PREG_SET_ORDER)) {
        continue;
    }

    foreach ($hit as $one) {
        $claims[$one[2]][$row[1]] = true;
    }
}

// ── What each question says about itself ──
$silent  = [];
$named   = 0;
$closed  = 0;
$total   = 0;
$current = null;

$mute      = [];
$seenSince = null;

foreach ($questions as $i => $line) {
    if (preg_match('/^## (OQ-\d+)/', $line, $m)) {
        // ⚠️ **A question that says nothing about itself was quieter than one that lied.** *OQ-093
        // had **no status line at all**, so the loop below skipped it: it appeared in no count,
        // open or closed, and was found on 2026-08-28 by listing the file by hand. **That is the
        // fault this whole check was written for, one level down — and it went unmeasured because
        // the check only ever looked at lines it had already found.***
        if ($seenSince !== null) {
            $mute[] = $seenSince;
        }

        $seenSince = $m[1];
        $current   = $m[1];
        $total++;

        continue;
    }

    // ⚠️ *Two forms count as saying something, because both are in use: a `*Status:*` line, and a
    // blockquoted «Closed <date> → D-nnn» as OQ-092 carries. **A third form is a bug, not a style.***
    if ($seenSince !== null && preg_match('/\*Status:\*|Closed \d{4}-\d\d-\d\d/u', $line)) {
        $seenSince = null;
    }

    if ($current === null || ! preg_match('/\*Status:\*\s*(.+)$/u', $line, $s)) {
        continue;
    }

    $said = strtolower(strip_tags($s[1]));
    $open = str_contains($said, 'open') && ! str_contains($said, 'answered') && ! str_contains($said, 'closed');

    if (! $open) {
        $closed++;
        $current = null;

        continue;
    }

    if (! isset($claims[$current])) {
        $current = null;

        continue;
    }

    // The next few lines have to name the deciding entry — the note this check exists for.
    $window = implode('', array_slice($questions, $i, 4));

    if (str_contains($window, 'nennt diese Frage')) {
        $named++;
    } else {
        $silent[$current] = array_keys($claims[$current]);
    }

    $current = null;
}

if ($seenSince !== null) {
    $mute[] = $seenSince;
}

if ($total < 50) {
    echo "Nur {$total} Fragen erkannt — die Datei wurde nicht richtig gelesen.\n";

    exit(1);
}

printf("Fragen: %d, davon %d beantwortet markiert\n", $total, $closed);

echo "\n== jede Frage sagt etwas ueber ihren Stand ==\n";

if ($mute === []) {
    printf("  ok   alle %d Fragen tragen eine Statuszeile oder einen Abschluss\n", $total);
} else {
    foreach ($mute as $question) {
        printf("  FEHLT %-7s sagt nichts ueber sich — weder Status noch Abschluss\n", $question);
    }
}

echo "\n== jede offene Frage, die eine Entscheidung beansprucht, verweist auf sie ==\n";

if ($silent === [] && $mute === []) {
    printf("  ok   %d offene Fragen tragen den Verweis auf ihre Entscheidung\n", $named);

    echo "\nall green\n";

    exit(0);
}

if ($silent === []) {
    printf("  ok   %d offene Fragen tragen den Verweis auf ihre Entscheidung\n", $named);

    printf("\n%d Fragen sagen nichts ueber ihren Stand.\n", count($mute));

    exit(1);
}

foreach ($silent as $question => $deciders) {
    printf("  FEHLT %-7s liest sich unberuehrt, obwohl %s sie beansprucht\n", $question, implode(', ', $deciders));
}

printf("\n%d verweisen, %d schweigen.\n", $named, count($silent));

exit(1);
