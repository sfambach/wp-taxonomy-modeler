<?php declare(strict_types=1);

/**
 * Does every superseded decision name the one that overtook it?
 *
 * ⚠️ **Why this exists, and it is not tidiness.** Measured 2026-08-26: **23 decisions are named as
 * superseded** somewhere in the log and **only 11 say so themselves**. The owner read the state of the
 * documentation and called it a chaos — *he was right, and the shape is the cause rather than the
 * volume.*
 *
 * ⚠️ **10 of the 23 are superseded only in part** — *«the storage **half** of D-211», «the German
 * branch **name** of D-185», «what D-401 **asked for**»* — and a one-word status column cannot say
 * *partly*. **So nobody sets it, and a half-overtaken decision reads exactly like a live one.**
 *
 * ⚠️ *That is the failure that cost three wrong answers in a single day: `D-312`'s bounding rule was
 * quoted after `D-411` abolished it, `D-133`'s storage rule after `D-232` replaced it, and
 * `SettingKey`'s own docblock still taught the abolished rule to whoever read it next. `PR-10` says to
 * look it up; looking it up does not help when the page cannot say it is out of date.*
 *
 * ⚠️ **What it asks for is a back-reference, not a status word.** The superseded entry has to name its
 * successor somewhere in its own row — *`⚠️ partly superseded by [D-nnn](#)`* is enough — because that
 * is the sentence a reader needs, and the only form that can carry «partly».
 *
 * ⚠️ *It needs no database, like `references-check`: the log is the whole subject.*
 *
 * Usage: php scripts/dev/supersession-check.php
 *
 * @see docs/NewConcept/90-decision-log.md
 */

$file = __DIR__ . '/../../docs/NewConcept/90-decision-log.md';

if (! is_readable($file)) {
    echo "Das Entscheidungslog ist nicht lesbar: {$file}\n";

    exit(1);
}

$rows = [];

foreach (file($file) as $line) {
    if (preg_match('/^\| (D-\d+) \|/', $line, $m)) {
        $rows[$m[1]] = $line;
    }
}

if (count($rows) < 100) {
    echo "Nur " . count($rows) . " Entscheidungen erkannt — das Log wurde nicht richtig gelesen.\n";

    exit(1);
}

printf("Entscheidungen: %d\n", count($rows));

$unanswered = [];
$answered   = 0;

foreach ($rows as $killer => $line) {
    // ⚠️ *Bounded to the same sentence: `[^.|]{0,110}` stops the match running past a full stop or a
    // cell wall, so «supersedes D-x» cannot pick up a `D-y` mentioned three clauses later.*
    //
    // ⚠️ **The look-behind is what makes it right, and a false alarm taught it.** `D-375` says
    // *«**[D-232](#) supersedes** [D-133](#)»* — reporting **another** decision's supersession, which
    // the naive pattern read as *D-375 supersedes D-133*. *So a hit only counts when no other decision
    // is named immediately before the verb: whoever stands there is the subject of the sentence.*
    if (! preg_match_all('/(?<before>[^.|]{0,40})[Ss]upersedes[^.|]{0,110}?(?<victim>D-\d+)/', $line, $hit, PREG_SET_ORDER)) {
        continue;
    }

    $named = [];

    foreach ($hit as $one) {
        if (preg_match('/D-\d+/', $one['before'])) {
            continue;
        }

        $named[$one['victim']] = true;
    }

    foreach (array_keys($named) as $victim) {
        if (! isset($rows[$victim]) || $victim === $killer) {
            continue;
        }

        // The victim has to name its successor — in its status cell or anywhere in its own row.
        if (str_contains($rows[$victim], $killer)) {
            $answered++;

            continue;
        }

        $unanswered[$victim][$killer] = true;
    }
}

echo "\n== jede ersetzte Entscheidung nennt ihren Nachfolger ==\n";

if ($unanswered === []) {
    printf("  ok   %d Ersetzungen, jede beidseitig verzeichnet\n", $answered);

    echo "\nall green\n";

    exit(0);
}

foreach ($unanswered as $victim => $killers) {
    printf("  FEHLT %-7s sagt nicht, dass %s sie ersetzt\n", $victim, implode(', ', array_keys($killers)));
}

printf(
    "\n%d beidseitig verzeichnet, %d einseitig — diese lesen sich wie gueltige Entscheidungen.\n",
    $answered,
    count($unanswered)
);

exit(1);

