<?php declare(strict_types=1);

/**
 * Does every in-document link point at a heading that exists?
 *
 * ⚠️ **Written because [D-469](../../docs/NewConcept/90-decision-log.md) made pointers load-bearing.**
 * *Consolidation says: one section owns a topic, every other mention is **a link to it**. That trades
 * a duplicated paragraph for a link — which is the better trade only as long as the link resolves. **A
 * pointer into a renamed heading is worse than the duplicate it replaced**: the duplicate was merely
 * stale, the pointer is silently gone.*
 *
 * ⚠️ *Sibling of `references-check.php`, which asks whether a cited `D-` or `OQ-` id exists. Same
 * shape, one level down: that check guards **ids**, this one guards **headings**.*
 *
 * ⚠️ **The anchor rule is GitHub's and it is not obvious**: lowercase, drop everything that is not a
 * letter, digit, space, hyphen or underscore, then spaces become hyphens. *So « — » leaves **two**
 * hyphens behind, which is why half the anchors in these documents have a double hyphen in them.*
 *
 * Usage: php scripts/dev/anchor-check.php
 *
 * @see docs/NewConcept/98-documentation-style.md
 */

$root = __DIR__ . '/../../docs/NewConcept/';

$files = glob($root . '*.md') ?: [];

if ($files === []) {
    echo "Keine Dokumente gefunden.\n";

    exit(1);
}

/** @return list<string> Every anchor a document offers. */
$anchorsOf = static function (string $text): array {
    preg_match_all('/^#{1,6} +(.+?)\s*$/mu', $text, $found);

    $anchors = [];

    foreach ($found[1] as $heading) {
        $slug = mb_strtolower($heading);
        $slug = preg_replace('/[^\p{L}\p{N}\s_-]+/u', '', $slug) ?? '';
        // ⚠️ **Nicht vorne trimmen, so unangenehm das aussieht.** *Eine Überschrift, die mit einem
        // Emoji beginnt, verliert das Zeichen und behält das Leerzeichen — GitHub macht daraus einen
        // **führenden Bindestrich**, und genau so stehen die Verweise in diesen Dokumenten. Ein
        // `trim()` an dieser Stelle meldet einen funktionierenden Anker als kaputt.*
        $anchors[] = str_replace(' ', '-', rtrim($slug));
    }

    return $anchors;
};

$offered = [];
$text    = [];

foreach ($files as $file) {
    $name = basename($file);
    $body = file_get_contents($file);

    if ($body === false) {
        printf("Nicht lesbar: %s\n", $name);

        exit(1);
    }

    $text[$name]    = $body;
    $offered[$name] = $anchorsOf($body);
}

$broken  = [];
$checked = 0;

foreach ($text as $name => $body) {
    // Links der Form ](ziel#anker) und ](#anker)
    preg_match_all('/\]\(([0-9A-Za-z._-]*)#([^)\s]+)\)/u', $body, $links, PREG_SET_ORDER);

    foreach ($links as $link) {
        [$whole, $target, $anchor] = $link;

        // ⚠️ *`](#)` ist in diesem Log Absicht und keine Adresse: der Log verweist so auf sich
        // selbst, weil die Zeile schon die `D-nnn` nennt. Nicht als Anker prüfen.*
        if ($anchor === '' || $anchor === '#') {
            continue;
        }

        $in = $target === '' ? $name : $target;

        if (! isset($offered[$in])) {
            // Ziel ausserhalb von docs/NewConcept — nicht diese Prüfung.
            continue;
        }

        $checked++;

        if (! in_array($anchor, $offered[$in], true)) {
            $broken[] = sprintf('%s → %s#%s', $name, $target === '' ? '(selbst)' : $target, $anchor);
        }
    }
}

printf("Anker geprueft: %d Verweise in %d Dokumenten\n", $checked, count($text));

if ($broken === []) {
    echo "\nall green\n";

    exit(0);
}

foreach (array_unique($broken) as $one) {
    printf("  FEHLT %s\n", $one);
}

printf("\n%d Verweise zeigen auf eine Ueberschrift, die es nicht gibt.\n", count(array_unique($broken)));

exit(1);
