<?php declare(strict_types=1);

/**
 * Does every id the repository cites actually exist?
 *
 * ⚠️ **This is the check that would have caught seven decisions cited in code and never written.**
 * `PR-3` says nothing is decided until it is in the log with an id; the failure mode is the reverse —
 * an id **in** the code that the log has never heard of, which reads as authority and is a dangling
 * reference. *Seven of them accumulated in one day.*
 */

chdir(__DIR__ . '/../..');

$log       = file_get_contents('docs/NewConcept/90-decision-log.md');
$questions = file_get_contents('docs/NewConcept/91-open-questions.md');

// What the log actually defines: a row that starts a table line with the id.
preg_match_all('/^\| (D-\d{3}) \|/m', $log, $m);
$defined = array_flip($m[1]);

preg_match_all('/^## (OQ-\d{3})/m', $questions, $q);
$asked = array_flip($q[1]);

$files = [];

foreach (['src', 'scripts', 'tests', 'docs/NewConcept', 'assets'] as $dir) {
    if (! is_dir($dir)) {
        continue;
    }

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

    foreach ($it as $f) {
        if (preg_match('/\.(php|md|css)$/', $f->getFilename())) {
            $files[] = $f->getPathname();
        }
    }
}

$danglingD = [];
$danglingQ = [];

foreach ($files as $f) {
    $text = (string) file_get_contents($f);

    preg_match_all('/D-\d{3}/', $text, $d);

    foreach (array_unique($d[0]) as $id) {
        if (! isset($defined[$id])) {
            $danglingD[$id][] = $f;
        }
    }

    preg_match_all('/OQ-\d{3}/', $text, $o);

    foreach (array_unique($o[0]) as $id) {
        if (! isset($asked[$id])) {
            $danglingQ[$id][] = $f;
        }
    }
}

ksort($danglingD);
ksort($danglingQ);

printf("Entscheide im Log:      %d\n", count($defined));
printf("Offene Fragen:          %d\n", count($asked));
printf("Dateien durchsucht:     %d\n\n", count($files));

if ($danglingD === []) {
    echo "  ok   jeder zitierte D-Verweis existiert\n";
} else {
    echo "  FEHLT im Log:\n";

    foreach ($danglingD as $id => $where) {
        printf("    %s  in %d Datei(en): %s\n", $id, count($where), implode(', ', array_slice($where, 0, 3)));
    }
}

if ($danglingQ === []) {
    echo "  ok   jeder zitierte OQ-Verweis existiert\n";
} else {
    echo "\n  FEHLT in den offenen Fragen:\n";

    foreach ($danglingQ as $id => $where) {
        printf("    %s  in %d Datei(en): %s\n", $id, count($where), implode(', ', array_slice($where, 0, 3)));
    }
}

echo "
", ($danglingD === [] && $danglingQ === []) ? "all green
" : "dangling references
";

exit(($danglingD === [] && $danglingQ === []) ? 0 : 1);
