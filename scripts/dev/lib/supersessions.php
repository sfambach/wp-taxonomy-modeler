<?php declare(strict_types=1);

/**
 * Wer wird von wem überholt — **die eine Stelle, die das weiss**.
 *
 * ⚠️ **Sie entstand, weil zwei Stellen dieselbe Frage stellten und jede ihre eigene Antwort baute.**
 * *`supersession-check.php` erkannte Ersetzungen an den **Verben** einer Zeile, `concept-index.php` an
 * **zwei deutschen Wendungen** — mit dem Ergebnis, dass am 2026-08-28 die Prüfung grün war und der
 * Index dieselbe Entscheidung als `agreed` führte. Der Eigentümer hat es als Misstrauen gegen die Datei
 * formuliert: «jedes Mal, wenn Du auf eine Id referenzierst, kann ich die nicht lesen, weil die nicht
 * mehr anständig geändert wird.» **Die Ursache war nicht die Länge, sondern zwei Kopien einer Regel.***
 *
 * ⚠️ **Diese Fassung ist die der Prüfung, wörtlich übernommen — nicht eine neue.** *Ein erster Anlauf
 * hatte eine eigene, strengere Erkennung geschrieben; sie zählte 18 wo die Prüfung 46 sah, und wurde
 * wieder entfernt. **Eine dritte Fassung wäre genau die Krankheit gewesen, die hier geheilt wird.***
 *
 * ⚠️ **Zweisprachig, und dass das nötig ist, hat Schaden gemacht.** *Die älteren Zeilen sagen
 * «supersedes», die neueren «überholt» oder «ersetzt». [D-484](../../../docs/NewConcept/90-decision-log.md)
 * überholte [D-036](../../../docs/NewConcept/90-decision-log.md) auf Deutsch, die Prüfung sah es nicht,
 * und D-036 stand weiter als gültig — **für 15 Ersetzungen war sie blind und sagte es nicht.***
 *
 * ⚠️ **Der Blick nach hinten ist das, was es richtig macht, und ein Fehlalarm hat es beigebracht.**
 * *[D-375](../../../docs/NewConcept/90-decision-log.md) sagt «**D-232 supersedes** D-133» — es berichtet
 * die Ersetzung **einer anderen** Entscheidung, was ein naives Muster als «D-375 ersetzt D-133» liest.
 * Ein Treffer zählt also nur, wenn unmittelbar vor dem Verb keine andere Entscheidung genannt ist:
 * **wer dort steht, ist das Subjekt des Satzes.***
 *
 * @see docs/NewConcept/90-decision-log.md
 */

/**
 * Jede Entscheidungszeile des Logs, nach Id.
 *
 * @return array<string, string>
 */
function taxmodDecisionRows(string $logFile): array
{
    $rows = [];

    foreach (file($logFile) ?: [] as $line) {
        if (preg_match('/^\| (D-\d+) \|/', $line, $m)) {
            $rows[$m[1]] = $line;
        }
    }

    return $rows;
}

/**
 * Wer überholt wen — **überholte Entscheidung ⇒ die Nachfolger, die es von sich behaupten**.
 *
 * ⚠️ *Nach dem **Opfer** geschlüsselt und nicht nach dem Nachfolger, weil beide Verbraucher das so
 * brauchen: die Prüfung fragt «nennt das Opfer seinen Nachfolger?», der Index fragt «ist diese Zeile
 * überholt?». Nach dem Nachfolger geschlüsselt müssten beide die Karte umdrehen.*
 *
 * @param  array<string, string>       $rows
 * @return array<string, list<string>>
 */
function taxmodSupersessions(array $rows): array
{
    $victims = [];

    foreach ($rows as $killer => $line) {
        // ⚠️ *Auf denselben Satz begrenzt: `[^.|]{0,110}` stoppt an einem Punkt und an einer Zellwand,
        // damit «ersetzt D-x» kein `D-y` aus drei Nebensätzen später aufsammelt.*
        if (! preg_match_all(
            '/(?<before>[^.|]{0,40})(?:[Ss]upersedes|überholt|ersetzt)[^.|]{0,110}?(?<victim>D-\d+)/u',
            $line,
            $hit,
            PREG_SET_ORDER
        )) {
            continue;
        }

        foreach ($hit as $one) {
            if (preg_match('/D-\d+/', $one['before'])) {
                continue;
            }

            // ⚠️ **Aktiv oder passiv, und im Deutschen sieht beides gleich aus.** *«D-312 **ist ersetzt
            // durch** D-411» ist ein **Rückverweis** — die Zeile sagt, was *ihr* geschah. «D-411
            // **ersetzt** D-312» ist ein **Anspruch**. Das englische `supersedes` kennt diese
            // Verwechslung nicht, das deutsche `ersetzt` schon: **beim Einbau der deutschen Verben
            // stand D-411 sofort als «ersetzt durch D-312» im Index — genau verkehrt herum.***
            //
            // ⚠️ *Sichtbar wurde es erst, weil Prüfung und Index seither **dieselbe** Stelle benutzen.
            // Mit zwei Fassungen wäre es in der einen falsch und in der anderen unsichtbar gewesen.*
            if (preg_match('/\b(?:ist|wurde|sind|wurden)\s*$/u', $one['before'])
                || preg_match('/(?:ersetzt|überholt)\s+durch/u', $one[0])
            ) {
                continue;
            }

            $victim = $one['victim'];

            if (! isset($rows[$victim]) || $victim === $killer) {
                continue;
            }

            $victims[$victim][$killer] = true;
        }
    }

    $out = [];

    foreach ($victims as $victim => $killers) {
        $out[$victim] = array_keys($killers);
    }

    return $out;
}
