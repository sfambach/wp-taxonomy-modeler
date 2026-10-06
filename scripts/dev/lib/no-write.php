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
 * ⚠️ **Ein Lauf zur Zeit — die Klammer holt vor der Transaktion eine benannte Sperre** (TASK-073).
 * *Gemessen am 2026-09-09: `cleanup-screen-check` starb im vollen Randlauf an einem Deadlock, weil
 * eine zweite Sitzung in derselben Sekunde Knoten unter demselben Elternknoten anlegte. Alle Wächter
 * arbeiten in derselben Datenbank, und die Klammer hält jede geschriebene Zeile bis zum Prozessende
 * gesperrt — **zwei Läufe zugleich sind ein Deadlock mit Ansage**, und der, der verliert, meldet rot,
 * obwohl seine Aussage stimmt.* **Die Sperre ist eine Verbindungssperre und keine Datei:** MySQL gibt
 * sie beim Trennen von selbst frei, also auch nach einem `exit(1)` in Zeile 200 — genau wie die
 * Klammer selbst. *Wer sie nicht bekommt, sagt es und geht mit einem eigenen Rückgabewert, statt
 * minutenlang stumm zu warten: ein Wächter, der hängt, sieht aus wie einer, der hängt.*
 *
 * @see tests/README.md
 */

if (! isset($GLOBALS['wpdb'])) {
    fwrite(STDERR, "no-write.php: kein \$wpdb — die Klammer gehört hinter das wp-load.\n");

    exit(2);
}

if (! defined('TAXMOD_NO_WRITE')) {
    define('TAXMOD_NO_WRITE', true);

    /** Der Rückgabewert eines Laufs, der die Sperre nicht bekam — weder grün (0) noch rot (1). */
    define('TAXMOD_NO_WRITE_BESETZT', 3);

    /** @var \wpdb $taxmodKlammerDb */
    $taxmodKlammerDb = $GLOBALS['wpdb'];

    // ⚠️ *Ein paar Sekunden Geduld für den Fall, dass der andere Lauf gerade zu Ende geht — aber
    // nicht die Minuten, die ein voller Randlauf dauert.* `1` heisst bekommen, `0` abgelaufen,
    // `NULL` ein Fehler; nur die `1` lässt den Lauf weiter.
    $taxmodKlammerName = $taxmodKlammerDb->prefix . 'taxmod_waechter';
    $taxmodKlammerFrei = $taxmodKlammerDb->get_var(
        $taxmodKlammerDb->prepare('SELECT GET_LOCK(%s, %d)', $taxmodKlammerName, 3)
    );

    if ((string) $taxmodKlammerFrei !== '1') {
        fwrite(
            STDERR,
            "no-write.php: ein anderer Wächterlauf hält die Sperre `{$taxmodKlammerName}` — "
            . "ein Lauf zur Zeit. Dieser Lauf hat nichts geprüft.\n"
        );

        exit(TAXMOD_NO_WRITE_BESETZT);
    }

    // ⚠️ *Ohne `autocommit = 0` beginnt nach einem eigenen `ROLLBACK` des Laufs keine neue
    // Umklammerung, und alles Weitere fiele wieder ungeschützt in den Bestand.*
    $taxmodKlammerDb->query('SET SESSION autocommit = 0');
    $taxmodKlammerDb->query('START TRANSACTION');

    register_shutdown_function(static function () use ($taxmodKlammerDb): void {
        $taxmodKlammerDb->query('ROLLBACK');
    });
}
