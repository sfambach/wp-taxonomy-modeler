<?php declare(strict_types=1);

/**
 * Die Klammer, die einen Wächterlauf am Schreiben hindert — **eine Stelle, alle Läufe**.
 *
 * ⚠️ **Sie entstand aus drei Funden an einem Tag, und alle drei standen auf dem Bildschirm des
 * Eigentümers.** *Am 2026-09-06 sah er die Einstellung `read_only` **zweimal** an seinem `Integer`;
 * gemessen war die zweite Kante `Integer --read_only--> Constants` — **von `package7-check.php`
 * angelegt und liegengelassen**. Am selben Tag lagen drei Knoten `__cv Zahl` aus
 * `converter-check.php` unter demselben `Integer`, und `seed-twice-check.php` hatte dreimal eine
 * deutsche `Adresse` gesät, weil er sie in `Address` umbenannt hatte und die Saat am Namen sucht.
 * **Dieselbe Krankheit in drei Läufen: aufräumen am Ende, und das Ende wird nicht immer erreicht.**
 *
 * ```mermaid
 * flowchart LR
 *   L["wp-load"] --> K["Klammer: START TRANSACTION"]
 *   K --> P["der Lauf prüft und schreibt"]
 *   P --> E["Ende, Abbruch oder Absturz"]
 *   E --> R["ROLLBACK beim Herunterfahren"]
 * ```
 *
 * **Die Zusage: ein Wächter darf lesen, so viel er will, und was er schreibt, überlebt ihn nicht.**
 * Aufräumen am Schluss bleibt richtig und wird nicht entfernt — es ist nur nicht mehr das, worauf
 * der Bestand sich verlässt. *Ein Lauf, der in Zeile 200 rot wird, räumt seine Zeile 400 nie ab;
 * genau daher kamen alle drei Funde.*
 *
 * ⚠️ **`register_shutdown_function` und nicht ein `finally`** — *ein `exit(1)` mitten in einer roten
 * Prüfung läuft an jedem `finally` vorbei, und rote Prüfungen sind der Normalfall, für den diese
 * Klammer gebaut ist.*
 *
 * ⚠️ **Wer innerhalb eines Laufs selbst zurückdrehen will, nimmt einen `SAVEPOINT`.** *Ein zweites
 * `START TRANSACTION` **bestätigt** in MySQL stillschweigend alles Bisherige — es wäre die Klammer,
 * die den Rückstand festschreibt, den sie verhindern soll.*
 *
 * @see tests/README.md
 */

if (! isset($GLOBALS['wpdb'])) {
    fwrite(STDERR, "no-write.php: kein \$wpdb — die Klammer gehört hinter das wp-load.\n");

    exit(2);
}

if (! defined('TAXMOD_NO_WRITE')) {
    define('TAXMOD_NO_WRITE', true);

    /** @var \wpdb $taxmodKlammerDb */
    $taxmodKlammerDb = $GLOBALS['wpdb'];

    // ⚠️ *Ohne `autocommit = 0` beginnt nach einem eigenen `ROLLBACK` des Laufs keine neue
    // Umklammerung, und alles Weitere fiele wieder ungeschützt in den Bestand.*
    $taxmodKlammerDb->query('SET SESSION autocommit = 0');
    $taxmodKlammerDb->query('START TRANSACTION');

    register_shutdown_function(static function () use ($taxmodKlammerDb): void {
        $taxmodKlammerDb->query('ROLLBACK');
    });
}
