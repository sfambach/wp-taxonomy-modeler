<?php declare(strict_types=1);

/**
 * Ein Formular abschicken, wie ein Browser es täte — aus dem Markup, nicht aus einer Liste von Hand.
 *
 * ⚠️ **Der Anlass:** *sein Befund am 2026-09-12: «read only verschwindet nach Speichern, das hatten wir jetzt
 * schon mehrfach».* Die Wächter schickten bis dahin nur die Felder, die sie selbst nannten — und sahen darum
 * nie, dass die Seite dasselbe Feld **zweimal** unter einem Namen trug und der Browser das zweite schickt.
 * Was der Browser schickt, entscheidet das Markup; also liest dieser Leser das Markup ([D-754](../../../docs/NewConcept/90-decision-log.md)).
 *
 * Gesammelt werden in Dokumentreihenfolge alle Steuerelemente, die zum Formular gehören — innen oder über
 * `form="…"` —: `input` (hidden, text, number, search, email, color, date, datetime-local; `checkbox` und
 * `radio` nur wenn `checked`), `select` (die gewählte `option`, sonst die erste), `textarea`. Ein `button` wird
 * nicht mitgeschickt; wer einen Akt auslösen will, gibt `do` selbst dazu.
 */

/**
 * @return array<string, mixed> Die Felder, wie PHP `$_POST` sie aufbaut — `a[b][c]` wird verschachtelt.
 */
function taxmodBrowserFields(string $html, string $formId): array
{
    $start = strpos($html, 'id="' . $formId . '"');

    if ($start === false) {
        return [];
    }

    $formStart = strrpos(substr($html, 0, $start), '<form');
    $formEnd   = strpos($html, '</form>', $start);
    $paare     = [];

    // ⚠️ *Wie ein Browser: was in einer Vorlage steht, gehört zu keinem Formular (D-845, die Vorlagen der Zusatzfunktionen). Gleich lang
    // ausgeblendet, damit die Stellen der übrigen Elemente bleiben.*
    $html = (string) preg_replace_callback('~<template\b.*?</template>~s', static fn (array $t): string => str_repeat(' ', strlen($t[0])), $html);

    preg_match_all('/<(input|select|textarea)\b([^>]*)>/i', $html, $m, PREG_OFFSET_CAPTURE);

    foreach ($m[0] as $i => [$tag, $pos]) {
        $attrs = $m[2][$i][0];
        $art   = strtolower($m[1][$i][0]);
        $innen = $formStart !== false && $pos > $formStart && $pos < $formEnd;
        $via   = preg_match('/\bform="([^"]*)"/', $attrs, $f) === 1 ? $f[1] : null;

        if (! ($innen && $via === null) && $via !== $formId) {
            continue;
        }

        if (preg_match('/(?<![-\w])name="([^"]*)"/', $attrs, $n) !== 1 || $n[1] === '') {
            continue;
        }

        $name = html_entity_decode($n[1], ENT_QUOTES);

        if ($art === 'input') {
            $typ = preg_match('/\btype="([^"]*)"/', $attrs, $t) === 1 ? strtolower($t[1]) : 'text';

            if (in_array($typ, ['submit', 'button', 'image', 'file'], true)) {
                continue;
            }

            if (in_array($typ, ['checkbox', 'radio'], true) && preg_match('/\bchecked\b/', $attrs) !== 1) {
                continue;
            }

            $wert = preg_match('/\bvalue="([^"]*)"/', $attrs, $v) === 1 ? html_entity_decode($v[1], ENT_QUOTES) : ($typ === 'checkbox' ? 'on' : '');
        } elseif ($art === 'select') {
            $ende = strpos($html, '</select>', $pos);
            $body = substr($html, $pos, $ende === false ? 0 : $ende - $pos);
            preg_match_all('/<option\b([^>]*)>/i', $body, $o);
            $wert = null;

            foreach ($o[1] as $k => $optAttrs) {
                $v = preg_match('/\bvalue="([^"]*)"/', $optAttrs, $ov) === 1 ? html_entity_decode($ov[1], ENT_QUOTES) : '';

                if ($k === 0) {
                    $wert = $v;
                }

                if (preg_match('/\bselected\b/', $optAttrs) === 1) {
                    $wert = $v;
                    break;
                }
            }

            if ($wert === null) {
                continue;
            }
        } else {
            $ende = strpos($html, '</textarea>', $pos);
            $wert = html_entity_decode(substr($html, $pos + strlen($tag), $ende === false ? 0 : $ende - $pos - strlen($tag)), ENT_QUOTES);
        }

        $paare[] = [$name, $wert];
    }

    $query = implode('&', array_map(static fn (array $p): string => rawurlencode($p[0]) . '=' . rawurlencode((string) $p[1]), $paare));
    parse_str($query, $felder);

    return $felder;
}

/**
 * Die Namen, die ein Formular mehr als einmal trägt — ausser Listen (`[]`) und dem Paar «verborgene 0 + Haken».
 *
 * @return array<string, int> Name ⇒ wie oft
 */
function taxmodDuplicateNames(string $html, string $formId): array
{
    $start = strpos($html, 'id="' . $formId . '"');

    if ($start === false) {
        return [];
    }

    $formStart = strrpos(substr($html, 0, $start), '<form');
    $formEnd   = strpos($html, '</form>', $start);
    $zaehler   = [];
    $arten     = [];
    $werte     = [];

    // ⚠️ *Wie ein Browser: was in einer Vorlage steht, gehört zu keinem Formular (D-845, die Vorlagen der Zusatzfunktionen). Gleich lang
    // ausgeblendet, damit die Stellen der übrigen Elemente bleiben.*
    $html = (string) preg_replace_callback('~<template\b.*?</template>~s', static fn (array $t): string => str_repeat(' ', strlen($t[0])), $html);

    preg_match_all('/<(input|select|textarea)\b([^>]*)>/i', $html, $m, PREG_OFFSET_CAPTURE);

    foreach ($m[0] as $i => [, $pos]) {
        $attrs = $m[2][$i][0];
        $innen = $formStart !== false && $pos > $formStart && $pos < $formEnd;
        $via   = preg_match('/\bform="([^"]*)"/', $attrs, $f) === 1 ? $f[1] : null;

        if (! ($innen && $via === null) && $via !== $formId) {
            continue;
        }

        if (preg_match('/(?<![-\w])name="([^"]*)"/', $attrs, $n) !== 1 || $n[1] === '' || str_ends_with($n[1], '[]')) {
            continue;
        }

        $typ = preg_match('/\btype="([^"]*)"/', $attrs, $t) === 1 ? strtolower($t[1]) : strtolower($m[1][$i][0]);
        $wert = preg_match('/\bvalue="([^"]*)"/', $attrs, $v) === 1 ? $v[1] : null;
        $zaehler[$n[1]] = ($zaehler[$n[1]] ?? 0) + 1;
        $arten[$n[1]][] = $typ;
        $werte[$n[1]][] = $typ === 'hidden' ? $wert : null;
    }

    $doppelt = [];

    foreach ($zaehler as $name => $n) {
        if ($n === 1) {
            continue;
        }

        $sorten = array_count_values($arten[$name]);

        // ⚠️ *Das Paar «hidden 0 + checkbox 1» ist gewollt: ohne Haken schickt der Browser die 0.*
        if ($n === 2 && ($sorten['hidden'] ?? 0) === 1 && (($sorten['checkbox'] ?? 0) === 1 || ($sorten['radio'] ?? 0) === 1)) {
            continue;
        }

        if (($sorten['radio'] ?? 0) === $n) {
            continue;
        }

        // ⚠️ *Dieselbe verborgene Angabe mehrmals mit demselben Wert — die Umstände einer Seite — ist kein Widerspruch.*
        if (($sorten['hidden'] ?? 0) === $n && count(array_unique($werte[$name])) === 1) {
            continue;
        }

        $doppelt[$name] = $n;
    }

    return $doppelt;
}

/** Den ersten Haken dieses Namens setzen — was ein Klick im Browser täte, bevor er speichert. */
function taxmodTick(string $html, string $name): string
{
    return (string) preg_replace_callback(
        '/<input\b[^>]*>/i',
        static function (array $m) use ($name, &$getan): string {
            if ($getan || ! str_contains($m[0], 'type="checkbox"') || ! str_contains($m[0], 'name="' . $name . '"')) {
                return $m[0];
            }

            $getan = true;

            return str_contains($m[0], ' checked') ? $m[0] : str_replace('<input ', '<input checked ', $m[0]);
        },
        $html
    );
}
