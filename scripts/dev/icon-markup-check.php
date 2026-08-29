<?php declare(strict_types=1);

/**
 * Schreibt ein Icon nur noch die eine Stelle, und bemasst es nur noch die eine Regel?
 *
 * ⚠️ **Diese Prüfung gibt es, weil der Eigentümer dasselbe *mehrfach* gemeldet hat** (2026-08-29):
 * *«Icons sind irgendwie nicht richtig aligned, die Grösse stimmt nicht … das Speichern-Symbol ist
 * wieder nach oben verschoben.»* **Ein Fehler, der wiederkommt, nachdem er einzeln behoben wurde,
 * ist kein Serienfehler, sondern ein fehlender Ort** — und ein Ort, den nichts bewacht, zerfällt
 * wieder in sechs.
 *
 * ⚠️ *Sie prüft **Quelltext**, nicht das Bild. Ob etwas am Ende gut aussieht, sagt sie nicht — sie
 * sagt, dass es nur **eine** Stelle gibt, an der es schiefgehen kann. Das ist weniger, als der
 * Eigentümer sehen will, und mehr, als jede Einzelkorrektur je gehalten hat.*
 *
 * Usage: php scripts/dev/icon-markup-check.php
 *
 * @see docs/NewConcept/30-renderer.md
 */

$root = dirname(__DIR__, 2);

$failed = 0;

$say = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);

    if (! $ok) {
        ++$failed;
    }
};

/** @return list<string> */
$dateien = static function (string $ordner, string $endung) use ($root): array {
    $found = [];
    $iter  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $ordner));

    foreach ($iter as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), $endung)) {
            $found[] = $file->getPathname();
        }
    }

    sort($found);

    return $found;
};

echo "== 1. das Markup: nur IconMarkup schreibt ein Icon ==\n";

$selbst   = 'IconMarkup.php';
$schuldig = [];

/**
 * Nur die Zeichenketten einer PHP-Datei, ohne Kommentare.
 *
 * ⚠️ **Die erste Fassung suchte im ganzen Dateitext und meldete zwei Fehlalarme** — *einen Docblock
 * in `Control.php`, der erklärt, was man **nicht** übergeben darf, und einen Kommentar im
 * Stylesheet, der erklärt, was entfernt wurde. **Ein Ausdruck in einem Satz über den Code ist kein
 * Code.** Dieselbe Verwechslung hatte `cleanup-screen-check.php` schon einmal, dort mit dem Wort
 * «changelog»; hier wird sie mit dem Tokenizer erledigt statt mit einem feineren Muster.*
 */
$zeichenketten = static function (string $file): string {
    $text = '';

    foreach (token_get_all(file_get_contents($file)) as $token) {
        if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
            $text .= $token[1];
        }
    }

    return $text;
};

foreach ($dateien('src', '.php') as $file) {
    if (str_ends_with($file, $selbst)) {
        continue;
    }

    // ⚠️ *Gesucht wird das **Zusammensetzen**, nicht das Wort. `dashicons-move` in einem Kommentar
    // oder ein Menü-Slug für WordPress sind keine Icons, die wir zeichnen — `<span class="dashicons`
    // in einer echten Zeichenkette ist genau der Ausdruck, der sechsmal dastand.*
    if (! str_contains($zeichenketten($file), '<span class="dashicons')) {
        continue;
    }

    $schuldig[] = str_replace([$root, '\\'], ['', '/'], $file);
}

$say(
    $schuldig === [],
    $schuldig === []
        ? 'keine Datei ausser IconMarkup schreibt ein Icon von Hand'
        : 'diese schreiben ihr Icon selbst: ' . implode(', ', $schuldig)
);

$icon = file_get_contents($root . '/src/Core/Renderer/IconMarkup.php');

$say(str_contains($icon, '<span class="'), 'IconMarkup schreibt es wirklich selbst');

echo "\n== 2. die Klasse: jedes gezeichnete Icon traegt sie ==\n";

// ⚠️ *Die gemeinsame Klasse ist der eigentliche Bau. Vorher war das einzig Gemeinsame `dashicons` —
// die Klasse von WordPress mit ihren 20px, worauf jeder Zusammenhang sie neu verkleinerte.
$say(
    substr_count($icon, "self::NAME") >= 2,
    'beide Wege setzen die gemeinsame Klasse'
);

// ⚠️ *Auch hier: die Kommentare raus, bevor gesucht wird. Der Kommentar, der erklärt, dass
// `vertical-align: text-bottom` entfernt wurde, ist sonst der Beweis, dass es noch da ist.*
$css = preg_replace('#/\*.*?\*/#s', '', file_get_contents($root . '/assets/admin.css'));

if ($css === null) {
    fwrite(STDERR, "Die Kommentare liessen sich nicht entfernen — nichts geprueft.\n");

    exit(2);
}

$say(str_contains($css, '.taxmod-icon,'), 'und das Stylesheet kennt sie');

echo "\n== 3. die Groesse: genau eine Regel legt sie fest ==\n";

// ⚠️ **Das ist die Zusage, die 2026-08-29 gebrochen war.** *Gemessen lagen **fünf** Regeln
// nebeneinander: zweimal hart `16px`, einmal `17px` als Inline-Style, einmal die Variable, und die
// Auswahlzelle gar keine — also die `20px`-Vorgabe von WordPress. Auf einer Seite standen 16, 17 und
// 20 Pixel gleichzeitig.*
$breiten = preg_match_all('/^\s*width:\s*(?:var\(--taxmod-icon|1[0-9]px)/m', $css, $_);

$say($breiten === 1, sprintf('genau eine Regel gibt einem Icon eine Breite (%d)', $breiten));

// ⚠️ *Eine Grösse als Inline-Style schlägt jede Regel im Stylesheet und ist darum der Rückfall, der
// wirklich passiert ist — an genau einer Stelle, dem Fragezeichen neben einer Überschrift.*
$inline = 0;

foreach ($dateien('src', '.php') as $file) {
    $inline += preg_match_all('/style="[^"]*(?:font-size|width|height):\s*\d+px/', file_get_contents($file), $_);
}

$say($inline === 0, sprintf('keine Icon-Groesse steht als Inline-Style im PHP (%d)', $inline));

echo "\n== 4. die Ausrichtung: der Baum richtet sich nicht mehr eigen aus ==\n";

// ⚠️ *`vertical-align: text-bottom` auf dem Baum-Icon war der zweite von drei Mechanismen. Zwei Icons
// gleicher Grösse sassen dadurch verschieden hoch — genau die Beobachtung des Eigentümers.*
$say(
    ! str_contains($css, 'vertical-align: text-bottom'),
    'kein zweiter Ausrichtungsmechanismus neben der gemeinsamen Regel'
);

// ⚠️ **Und die Diskette hat einen Kasten wie jedes andere Icon.** *Sie war das einzige ohne `width`
// und `height` — nur eine Schriftgrösse, während jeder Nachbar einen festen Kasten hatte.*
$say(
    ! preg_match('/\.taxmod-icon-glyph\s*\{[^}]*font-size/', $css),
    'der Glyph bemasst sich nicht mehr selbst'
);

printf("\n%s\n", $failed === 0 ? 'all green' : sprintf('%d FEHLER', $failed));

exit($failed === 0 ? 0 : 1);
