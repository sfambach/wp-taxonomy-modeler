<?php declare(strict_types=1);

/**
 * Erreicht eine Entscheidung das Dokument, das sie selbst als betroffen nennt?
 *
 * ⚠️ **Der Eigentümer hat danach gefragt:** *«wäre es mal an der Zeit, die Doku zu updaten mit all den
 * Konzeptänderungen, die wir gemacht haben?»* — und die ehrliche Antwort war eine Zahl: **370 von 728**
 * Zusagen «diese Entscheidung betrifft dieses Dokument» waren unerfüllt, davon **52 vom selben Tag**.
 *
 * ⚠️ **Was diese Prüfung misst und was nicht, damit sie nicht überschätzt wird.** *Sie prüft, ob das
 * Dokument die **Id** nennt. Ein Dokument kann eine Regel vollständig beschreiben, ohne sie zu
 * zitieren — **die Zahl ist also eine Obergrenze für echte Lücken und eine Untergrenze für fehlende
 * Verweise**. Sie ersetzt kein Lesen. Was sie kann, ist verhindern, dass die Zahl **wächst**.*
 *
 * ⚠️ **Eine Ratsche und keine Ampel, und das ist Absicht.** *Bei 370 offenen Lücken rot zu werden hiesse,
 * dass die Prüfung ab dem ersten Tag ignoriert wird — und eine Prüfung, die immer rot ist, ist keine.
 * **Sie hält die Obergrenze fest und fällt, wenn sie überschritten wird.** Wer Lücken schliesst, senkt
 * die Zahl hier mit; wer neue aufreisst, merkt es sofort.*
 *
 * ⚠️ *Sie zählt nur die Konzeptdokumente **00–70**. `90` bis `99` sind Log, Fragen, Arbeitsliste und
 * Index — sie beschreiben nicht, **worum** es geht, und eine Entscheidung muss sich dort nicht
 * wiederfinden.*
 *
 * Usage: php scripts/dev/doc-reach-check.php
 *
 * @see docs/NewConcept/98-documentation-style.md
 */

require __DIR__ . '/lib/supersessions.php';

/**
 * Wie viele Lücken hingenommen werden.
 *
 * ⚠️ **Diese Zahl darf nur nach unten.** *Stand 2026-08-28 nach dem ersten Durchgang: **370 zu Beginn, 316 danach** — 54 geschlossen, davon 52 vom selben Tag. Auf **315** am selben Tag mit [D-496](../../docs/NewConcept/90-decision-log.md). Auf **314** mit [D-499](../../docs/NewConcept/90-decision-log.md)/[D-500](../../docs/NewConcept/90-decision-log.md). Sie steht hier
 * und nicht in einer Datei daneben, damit ihre Änderung im Diff einer Entscheidung auftaucht — eine
 * stillschweigend erhöhte Obergrenze wäre genau die Sorte Nachgeben, die eine Ratsche verhindern soll.*
 */
const HINGENOMMEN = 311;

$root = __DIR__ . '/../../docs/NewConcept/';
$rows = taxmodDecisionRows($root . '90-decision-log.md');

if (count($rows) < 100) {
    echo 'Nur ' . count($rows) . " Entscheidungen erkannt — das Log wurde nicht richtig gelesen.\n";

    exit(1);
}

$inhalt = [];

foreach (glob($root . '*.md') ?: [] as $file) {
    $body = file_get_contents($file);

    if ($body === false) {
        printf("Nicht lesbar: %s\n", basename($file));

        exit(1);
    }

    $inhalt[basename($file)] = $body;
}

$luecken  = [];
$versucht = 0;

foreach ($rows as $id => $line) {
    $cells = explode(' | ', rtrim($line, " |\r\n"));
    $datum = trim($cells[1] ?? '');

    // Die zweitletzte Zelle nennt die betroffenen Dokumente.
    preg_match_all('/\((\d{2}-[a-z0-9-]+\.md)\)/', $cells[count($cells) - 2] ?? '', $m);

    foreach (array_unique($m[1]) as $dok) {
        if (! isset($inhalt[$dok]) || (int) substr($dok, 0, 2) > 70) {
            continue;
        }

        ++$versucht;

        if (! str_contains($inhalt[$dok], $id)) {
            $luecken[$dok][] = $id . ' (' . $datum . ')';
        }
    }
}

$offen = 0;

foreach ($luecken as $liste) {
    $offen += count($liste);
}

printf("Zusagen «diese Entscheidung betrifft dieses Dokument»: %d\n", $versucht);
printf("davon nennt das Dokument die Entscheidung nicht:       %d  (hingenommen: %d)\n\n", $offen, HINGENOMMEN);

ksort($luecken);

foreach ($luecken as $dok => $liste) {
    printf("  %-30s %3d\n", $dok, count($liste));
}

if ($offen > HINGENOMMEN) {
    printf(
        "\nDie Zahl ist um %d gestiegen. Entweder die Doku nachziehen oder — mit einer Entscheidung —\n"
        . "die Obergrenze in dieser Datei anheben.\n",
        $offen - HINGENOMMEN
    );

    exit(1);
}

if ($offen < HINGENOMMEN) {
    printf(
        "\n⚠️  %d Lücken weniger als hingenommen. Setz `HINGENOMMEN` auf %d, sonst haelt die Ratsche nicht.\n",
        HINGENOMMEN - $offen,
        $offen
    );

    exit(1);
}

echo "\nall green\n";

exit(0);
