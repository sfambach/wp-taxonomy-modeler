<?php declare(strict_types=1);

/**
 * The core calls no WordPress: no `do_action`, `add_action`, `apply_filters`, `add_filter`, and no `wp_*` function anywhere
 * below `src/Core/` (renderer plan step 2, [D-627](../../docs/NewConcept/90-decision-log.md), `CD-1`). The one rule the
 * events package can break — so it stands before the first event, not after it.
 *
 * ⚠️ *Read from PHP's own tokens, so a comment or a string that names a hook is no finding — only a call is.*
 *
 *     php scripts/dev/core-has-no-hooks-check.php
 */

const CORE = __DIR__ . '/../../src/Core';

/** @return list<string> «line name()» for each WordPress call in the code. */
function wordpressCalls(string $code): array
{
    $tokens = token_get_all($code);
    $aus    = [];

    foreach ($tokens as $i => $token) {
        if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }

        $name = ltrim($token[1], '\\');

        if (! preg_match('/^(do_action|add_action|apply_filters|add_filter|wp_[a-z0-9_]+)$/i', $name)) {
            continue;
        }

        // Only a call: the next meaningful token is «(», and the one before is no «->», «::», «function» or «new».
        $next = $i + 1;
        while (isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE) {
            ++$next;
        }

        $prev = $i - 1;
        while (isset($tokens[$prev]) && is_array($tokens[$prev]) && $tokens[$prev][0] === T_WHITESPACE) {
            --$prev;
        }

        $vorher = $tokens[$prev] ?? null;

        if (($tokens[$next] ?? null) !== '('
            || (is_array($vorher) && in_array($vorher[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true))) {
            continue;
        }

        $aus[] = $token[2] . ' ' . $name . '()';
    }

    return $aus;
}

$funde   = [];
$dateien = 0;

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(CORE, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }

    ++$dateien;

    foreach (wordpressCalls((string) file_get_contents($file->getPathname())) as $fund) {
        $funde[] = substr($file->getPathname(), strlen(CORE) + 1) . ':' . $fund;
    }
}

printf("%d core files read\n", $dateien);

foreach ($funde as $fund) {
    echo "  FAIL {$fund}\n";
}

// ⚠️ *The counter-proof: the check must see a call when there is one, or it is green for the wrong reason.*
$probe = wordpressCalls("<?php\ndo_action('x');\n\\wp_cache_get('y');\n\$a->wp_thing();\n// add_filter('z')\nfunction wp_own() {}\n");

if ($dateien === 0 || $probe !== ['2 do_action()', '3 wp_cache_get()']) {
    echo "  FAIL the check does not see what it looks for: " . implode(', ', $probe) . "\n";
    exit(1);
}

echo $funde === [] ? "No WordPress call in the core.\n" : count($funde) . " FAILED\n";

exit($funde === [] ? 0 : 1);
