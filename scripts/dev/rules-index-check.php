<?php declare(strict_types=1);

/**
 * Ist das Regelverzeichnis noch wahr?
 *
 *     php scripts/dev/rules-index-check.php
 *
 * ⚠️ **Nur ein Anschluss an den Randlauf, keine eigene Logik.** *Der Randlauf ist ein Muster über
 * `scripts/dev/*-check.php` ([`tests/README.md`](../../tests/README.md)) und ruft ohne Argumente auf —
 * der Erzeuger würde dabei **schreiben statt prüfen**. Deshalb diese Datei: sie trägt den Prüfmodus
 * in den Lauf. Die Arbeit steht in [`rules-index.php`](rules-index.php).*
 *
 * ⚠️ *Wird rot, sobald jemand eine Regel einführt, ohne dass sie im Verzeichnis auftaucht — **das ist
 * der eigentliche Zweck des Verzeichnisses**, nicht die Datei selbst. Der Eigentümer konnte den
 * Regelsatz nicht übergeben, weil niemand wusste, wieviele Regeln es gibt und wo sie stehen.*
 *
 * @see docs/NewConcept/02-rules-index.md
 */

$erzeuger = __DIR__ . '/rules-index.php';

if (! is_readable($erzeuger)) {
    fwrite(STDERR, "Der Erzeuger fehlt: {$erzeuger}\n");

    exit(2);
}

$befehl = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($erzeuger) . ' --check';

passthru($befehl, $ausgang);

exit($ausgang);
