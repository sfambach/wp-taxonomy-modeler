<?php declare(strict_types=1);

/**
 * D-1501 (D-1500 C1–C3): every English software string a Taxmod screen shows has its German text in the plugin's
 * `languages/<domain>-de_DE.l10n.php`. A ratchet (PR-9): it reads each repository's `src/` with the PHP tokenizer, takes the
 * literal first argument of `__`, `esc_html__`, `_n` … in the repository's own text domain, leaves out the ability
 * descriptions (`*Abilities.php`, read by agents, not by a person) and source texts already German, and turns red when a
 * repository has more strings without German than its ceiling — or when a German text loses a placeholder of its source.
 *
 *     php scripts/dev/translation-check.php   (this repository and every wp_tax_* beside it)
 *
 * Script words (wp.i18n) are counted the same way against the script's JSON in `languages/`.
 *
 * Needs no WordPress.
 *
 * @see docs/NewConcept/90-decision-log.md D-1501
 */

/**
 * Counted 2026-11-20; only ever lowered. A repository not named here is not checked: Lager, Fertigung, Etiketten, Verkauf
 * and Versand write most of their source texts in German, which this count cannot tell from English.
 */
const CEILINGS = [
    'wp-taxonomy-modeler' => 0,
    'wp_tax_api_amazon'   => 0,
    'wp_tax_api_dhl'      => 0,
    'wp_tax_api_ebay'     => 0,
    'wp_tax_api_gls'      => 0,
    'wp_tax_api_jlcpcb'   => 0,
    'wp_tax_api_mouser'   => 0,
    'wp_tax_mail'         => 0,
];

const SINGLE  = ['__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e'];
const CONTEXT = ['_x', '_ex', 'esc_html_x', 'esc_attr_x'];
const PLURAL  = ['_n', '_n_noop'];

/**
 * The literal arguments of one call, or null where an argument is not a plain string.
 *
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 * @return list<string|null>
 */
function literalArguments(array $tokens, int $open): array
{
    $arguments = [];
    $current   = null;
    $literal   = true;
    $depth     = 0;

    for ($k = $open + 1, $n = count($tokens); $k < $n; $k++) {
        $token = $tokens[$k];

        if ($token === '(' || $token === '[') {
            $depth++;
            $literal = false;
            continue;
        }

        if ($token === ')' || $token === ']') {
            if ($depth === 0) {
                $arguments[] = $literal ? $current : null;
                break;
            }

            $depth--;
            continue;
        }

        if ($token === ',' && $depth === 0) {
            $arguments[] = $literal ? $current : null;
            $current     = null;
            $literal     = true;
            continue;
        }

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && $depth === 0 && $current === null) {
            $current = stripcslashes(substr($token[1], 1, -1));
            $current = $token[1][0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($token[1], 1, -1)) : $current;
            continue;
        }

        $literal = false;
    }

    return $arguments;
}

/** @return array<string, string> key (context \4 msgid) => msgid */
function sourceStrings(string $repository, string $domain): array
{
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repository . '/src', FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $path = (string) $file;

        if (! str_ends_with($path, '.php') || str_ends_with($path, 'Abilities.php')) {
            continue;
        }

        $tokens = token_get_all((string) file_get_contents($path));

        foreach ($tokens as $i => $token) {
            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $function = $token[1];

            if (! in_array($function, [...SINGLE, ...CONTEXT, ...PLURAL], true)) {
                continue;
            }

            $j = $i + 1;

            while (is_array($tokens[$j] ?? null) && $tokens[$j][0] === T_WHITESPACE) {
                $j++;
            }

            if (($tokens[$j] ?? null) !== '(') {
                continue;
            }

            $arguments = literalArguments($tokens, $j);
            $message   = $arguments[0] ?? null;
            [$context, $ownDomain] = match (true) {
                in_array($function, CONTEXT, true) => [$arguments[1] ?? null, $arguments[2] ?? null],
                $function === '_n'                 => [null, $arguments[3] ?? null],
                $function === '_n_noop'            => [null, $arguments[2] ?? null],
                default                            => [null, $arguments[1] ?? null],
            };

            if (! is_string($message) || $ownDomain !== $domain || preg_match('/[äöüßÄÖÜ]/u', $message) === 1 || preg_match('/[A-Za-z]{2,}/', $message) !== 1) {
                continue;
            }

            $found[($context !== null ? $context . "\4" : '') . $message] = $message;
        }
    }

    return $found;
}

$base   = dirname(__DIR__, 3);
$failed = 0;

foreach (CEILINGS as $name => $ceiling) {
    $repository = $name === 'wp-taxonomy-modeler' ? dirname(__DIR__, 2) : $base . '/' . $name;
    $main       = glob($repository . '/*.php') ?: [];
    $header     = implode('', array_map(static fn (string $f): string => (string) file_get_contents($f, false, null, 0, 2000), $main));

    if (! is_dir($repository . '/src') || preg_match('/Text Domain:\s*(\S+)/', $header, $match) !== 1) {
        echo "skip {$name}: not checked out here\n";
        continue;
    }

    $domain = $match[1];
    $file   = $repository . '/languages/' . $domain . '-de_DE.l10n.php';
    $german = is_file($file) ? (array) ((include $file)['messages'] ?? []) : [];
    $source = sourceStrings($repository, $domain);
    $miss   = array_diff_key($source, $german);
    $broken = [];

    foreach (array_intersect_key($german, $source) as $key => $text) {
        preg_match_all('/%(?:\d\$)?[sdf]/', $source[$key], $want);
        $first = explode("\0", (string) $text)[count(explode("\0", (string) $text)) - 1];
        preg_match_all('/%(?:\d\$)?[sdf]/', $first, $have);
        $a = $want[0];
        $b = $have[0];
        sort($a);
        sort($b);

        if ($a !== $b && ! str_contains((string) $text, "\0")) {
            $broken[] = $source[$key];
        }
    }

    // The script words (wp.i18n, D-1500): each `__( '…'` in a script under assets/ has its German in the JSON WordPress loads
    // for that script, languages/<domain>-de_DE-<md5 of its path>.json.
    foreach (glob($repository . '/assets/*.js') ?: [] as $script) {
        preg_match_all("/__\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($script), $calls);

        if ($calls[1] === []) {
            continue;
        }

        $relative = substr($script, strlen($repository) + 1);
        $json     = $repository . '/languages/' . $domain . '-de_DE-' . md5($relative) . '.json';
        $known    = is_file($json) ? (array) (json_decode((string) file_get_contents($json), true)['locale_data']['messages'] ?? []) : [];

        foreach (array_unique($calls[1]) as $text) {
            $text = str_replace("\\'", "'", $text);

            if (! isset($known[$text])) {
                $miss[$relative . ': ' . $text] = $relative . ': ' . $text;
            }
        }
    }

    $ok = count($miss) <= $ceiling && $broken === [];
    $failed += $ok ? 0 : 1;
    printf("%s %s: %d strings, %d without German (ceiling %d), %d with a lost placeholder\n", $ok ? 'ok  ' : 'FAIL', $name, count($source), count($miss), $ceiling, count($broken));

    foreach (array_slice([...array_values($miss), ...$broken], 0, 20) as $text) {
        echo '       ', $text, "\n";
    }
}

exit($failed === 0 ? 0 : 1);
