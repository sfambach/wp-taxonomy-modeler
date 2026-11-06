<?php declare(strict_types=1);

/**
 * Prints single decisions out of the log, so nobody has to read the whole file (D-1210).
 *
 *     php scripts/dev/decision.php D-1210 1186        # these entries, whole
 *     php scripts/dev/decision.php --grep Scanner     # id and the first 160 characters of each entry naming it
 *
 * ⚠️ *The log is well over a megabyte; read into a conversation whole it costs more than a day of work. What is in force
 * today stands in `03-entscheidungsverzeichnis.md` — this tool is for the wording of one entry (`PR-10`).*
 *
 * @see docs/NewConcept/90-decision-log.md
 */

const LOG = __DIR__ . '/../../docs/NewConcept/90-decision-log.md';

$args = array_slice($argv, 1);

if ($args === []) {
    fwrite(STDERR, "Usage: decision.php D-<nnn> [...] | --grep <text>\n");
    exit(2);
}

$lines = file(LOG, FILE_IGNORE_NEW_LINES) ?: [];

if ($args[0] === '--grep') {
    $text = mb_strtolower(implode(' ', array_slice($args, 1)));

    foreach ($lines as $line) {
        if (preg_match('/^\| (D-\d+) \|/', $line, $m) === 1 && str_contains(mb_strtolower($line), $text)) {
            echo $m[1], '  ', mb_substr(strip_tags($line), 0, 160), "\n";
        }
    }

    exit(0);
}

$wanted = array_map(static fn (string $id): string => 'D-' . ltrim(strtoupper($id), 'D-'), $args);
$found  = [];

foreach ($lines as $line) {
    if (preg_match('/^\| (D-\d+) \|/', $line, $m) === 1 && in_array($m[1], $wanted, true)) {
        echo $line, "\n\n";
        $found[] = $m[1];
    }
}

$missing = array_diff($wanted, $found);

if ($missing !== []) {
    fwrite(STDERR, 'Not in the log: ' . implode(', ', $missing) . "\n");
    exit(1);
}
