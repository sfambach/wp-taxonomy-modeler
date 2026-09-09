<?php declare(strict_types=1);

/**
 * Hält die Klammer für eine Weile — damit ein zweiter Prozess an ihr gemessen werden kann.
 *
 *     php scripts/dev/lib/klammer-probe.php <WordPress-Ordner> <Sekunden>
 *
 * ⚠️ **Nur für `no-model-write-check.php`, Abschnitt 4.** *Ein Wächter darf nicht zu zweit laufen
 * (TASK-073); ob die Klammer das wirklich verhindert, lässt sich nur mit zwei echten Prozessen
 * messen: dieser hier nimmt die Sperre und wartet, der zweite muss mit dem Rückgabewert der Klammer
 * abbrechen — und nicht hängen, und nicht rot werden.* Er schreibt nichts, und liegt in `lib/`, damit
 * der Glob des Randlaufs ihn nicht als Wächter mitzählt.
 *
 * @see scripts/dev/lib/no-write.php
 */

define('WP_USE_THEMES', false);

$root = rtrim((string) ($argv[1] ?? ''), '/\\');

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "klammer-probe.php: kein wp-load.php unter `{$root}`.\n");

    exit(2);
}

require $root . '/wp-load.php';
require __DIR__ . '/no-write.php';

echo "Klammer gehalten\n";
flush();

sleep(max(0, (int) ($argv[2] ?? 0)));

exit(0);
