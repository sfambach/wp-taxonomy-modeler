<?php declare(strict_types=1);

/**
 * Das Fundament, nach Gewicht — welche der ersten hundert Entscheidungen am meisten trägt.
 *
 *     php scripts/dev/foundation-weight.php [wieviele]
 *
 * ⚠️ **Kein Wächter, sondern eine Reihenfolge.** *Der Eigentümer hat nach Struktur gefragt: «ich frage
 * mich, wie wir irgendwie strukturierter an die Sache drangehen können, dass wir nicht immer aneinander
 * vorbeireden, und dass irgendwelche Sachen aus misinterpretierten Chatnachrichten entstehen, die so gar
 * nicht angedacht waren.»* **Dieses Skript beantwortet die Frage «womit anfangen».**
 *
 * ⚠️ **Der Anlass, gemessen am 2026-09-01:**
 *
 * | Block | Entscheidungen mit einem Satz des Eigentümers |
 * |---|---|
 * | D-001–100 | **3 %** |
 * | D-101–200 | 31 % |
 * | D-201–300 | 73 % |
 * | D-301–400 | 74 % |
 * | D-401–500 | 88 % |
 * | D-501–562 | **90 %** |
 *
 * *Die Gewohnheit, ihn zu zitieren, wuchs von 3 % auf 90 %. **Das Fundament ist der Teil, der am
 * wenigsten auf ihn zurückführbar ist — und er wird am meisten zitiert:** 267 Verweise aus 186 späteren
 * Entscheidungen und 212 Nennungen im Quelltext.*
 *
 * ⚠️ **«Ohne Zitat» heisst nicht «nicht von ihm».** *Das frühe Buch zitierte generell nicht — bei 3 %
 * sagt das Fehlen fast nichts über eine einzelne Zeile. **Was es sagt, ist, wo man nachfragen muss**, und
 * in welcher Reihenfolge: nach Gewicht, nicht nach Nummer.*
 *
 * ⚠️ *Der Fall, der es ausgelöst hat: `relation_records.path` steht wegen [D-134](../../docs/NewConcept/90-decision-log.md)
 * da — «required by [D-133](../../docs/NewConcept/90-decision-log.md)'s flattening» —, D-133 wurde am Tag
 * danach von [D-232](../../docs/NewConcept/90-decision-log.md) abgelöst, und der Eigentümer sagt seit
 * Tagen, dass ein Pfad nicht zu seinem Wortschatz gehört. **Er hatte recht, und niemand konnte es sehen,
 * weil die Frage «ist das von ihm» nirgends stand.***
 *
 * @see docs/NewConcept/90-decision-log.md
 */

$wieviele = isset($argv[1]) ? max(1, (int) $argv[1]) : 20;

$log = dirname(__DIR__, 2) . '/docs/NewConcept/90-decision-log.md';

if (! is_readable($log)) {
    fwrite(STDERR, "Kein Entscheidungsbuch unter {$log}\n");
    exit(2);
}

/** @var array<int, string> Nummer => ihre Zeile */
$zeilen = [];

foreach (file($log) ?: [] as $z) {
    if (preg_match('/^\| D-(\d+) \|/', $z, $t)) {
        $zeilen[(int) $t[1]] = $z;
    }
}

if ($zeilen === []) {
    fwrite(STDERR, "Keine Entscheidungszeile erkannt — das Muster passt nicht mehr.\n");
    exit(2);
}

/**
 * Ob in dieser Zeile der Eigentümer zu Wort kommt.
 *
 * ⚠️ *Sechs Wendungen, weil das Buch zweisprachig gewachsen ist. **Ein Fehlen ist ein Hinweis, kein
 * Urteil** — siehe der Kopf dieser Datei.*
 */
$hatSeineStimme = static function (string $zeile): bool {
    foreach (['Der Eigentümer', 'The owner', 'sein Wort', 'seine Bitte', 'his word', 'Auf sein'] as $wendung) {
        if (str_contains($zeile, $wendung)) {
            return true;
        }
    }

    return false;
};

// ⚠️ *Der Quelltext wird **einmal** eingelesen, nicht je Entscheidung — sonst wäre es hundert Läufe
// über den Baum (`CD-7`s Argument, auf Dateien statt auf Abfragen angewandt).*
$quelltext = '';

$lauf = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src'));

foreach ($lauf as $datei) {
    if ($datei instanceof SplFileInfo && $datei->getExtension() === 'php') {
        $quelltext .= (string) file_get_contents($datei->getPathname());
    }
}

$gewicht = [];

foreach (array_keys($zeilen) as $nummer) {
    if ($nummer > 100) {
        continue;
    }

    $marke = sprintf('D-%03d', $nummer);

    $ausLog = 0;

    foreach ($zeilen as $andere => $zeile) {
        if ($andere !== $nummer && str_contains($zeile, '[' . $marke . ']')) {
            ++$ausLog;
        }
    }

    $gewicht[$marke] = [
        'log'     => $ausLog,
        'code'    => substr_count($quelltext, $marke),
        'stimme'  => $hatSeineStimme($zeilen[$nummer]),
        'zurueck' => str_contains($zeilen[$nummer], 'Überholt durch')
            || str_contains($zeilen[$nummer], 'uperseded by'),
        'titel'   => trim(substr((string) preg_replace('/\s+/', ' ', strip_tags(explode('|', $zeilen[$nummer])[3] ?? '')), 0, 84)),
    ];
}

uasort($gewicht, static fn (array $a, array $b): int => ($b['log'] + $b['code']) <=> ($a['log'] + $a['code']));

$ohneStimme = 0;

foreach ($gewicht as $g) {
    if (! $g['stimme']) {
        ++$ohneStimme;
    }
}

printf(
    "Die ersten %d Entscheidungen: %d ohne einen Satz des Eigentümers.\n",
    count($gewicht),
    $ohneStimme
);
printf("Nach Gewicht geordnet, die schwersten %d:\n\n", $wieviele);

$i = 0;

foreach ($gewicht as $id => $g) {
    if (++$i > $wieviele) {
        break;
    }

    printf(
        "  %-7s %3d Verweise (%2d Buch, %3d Code)  %-18s%s\n      %s\n",
        $id,
        $g['log'] + $g['code'],
        $g['log'],
        $g['code'],
        $g['stimme'] ? 'mit seiner Stimme' : 'OHNE seine Stimme',
        $g['zurueck'] ? '  · ZURÜCKGENOMMEN' : '',
        $g['titel']
    );
}

echo "\nZu jeder dieser Zeilen ist **eine** Frage zu stellen: ist das seine Entscheidung oder meine?\n";
echo "Was seine ist, bekommt seinen Satz. Was meine ist, bekommt seine Zustimmung — oder fällt.\n";

exit(0);
