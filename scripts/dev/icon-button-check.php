<?php declare(strict_types=1);

/**
 * No box around an icon button, measured on the rendered page — `PR-9`.
 *
 * ⚠️ **The owner has reported this four times and every fix so far was written against the wrong
 * cause.** First the borderless rule was scoped too narrowly, then it lost on specificity, then
 * {@see \Taxmod\Core\Renderer\ControlMarkup} became the one place that composes a button. **On
 * 2026-08-28 two save buttons were boxed again and the markup side was blameless**: measured in the
 * browser, `put_labels` and `save_field` reached it as `<button class="button">💾</button>`, 49×41
 * among 24×17 neighbours, wearing WordPress's blue secondary border.
 *
 * ⚠️ **The cause was a positional argument.** `Control::__construct()` takes `$label` third and
 * `$glyph` tenth; four of the five save buttons handed the diskette in third. `ControlMarkup` decides
 * «this is an icon» from `icon` and `glyph`, so a diskette in the label slot is *a word* — drawn as
 * text, and correctly given no borderless class. *Nothing in the markup was duplicated and nothing in
 * the stylesheet was missing; a check that looked for a fourth copy of the `<button>` would have
 * passed.*
 *
 * ⚠️ **So this check asks the question the eye asks: is anything that looks like an icon wearing a
 * box?** It reads the drawn page, and it is deliberately not a search for a class in the source — the
 * class was there, in the one place, all along.
 *
 * ⚠️ *It also guards [D-397](../../docs/NewConcept/90-decision-log.md), which was quietly dead:
 * «the two sizes reach the stylesheet as custom properties … the fallbacks are what the file used to
 * hard-code» — and `.taxmod-icon-button .dashicons` hard-coded `17px`, one class pair like the rule
 * that used the property, so it simply came later and won. **Raising `--taxmod-icon` to 30px moved no
 * glyph anywhere on the page.** A control the owner had walked himself did nothing and nothing said
 * so.*
 *
 * Usage: php scripts/dev/icon-button-check.php
 *
 * @see docs/NewConcept/30-renderer.md
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';
require __DIR__ . '/../../vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use Taxmod\Core\Renderer\Control;
use Taxmod\Core\Renderer\ControlMarkup;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);

global $wpdb;

$prefix = $wpdb->prefix . 'taxmod_';
$failed = 0;

function check(string $what, bool $held, string $saw = ''): void
{
    global $failed;

    if (! $held) {
        $failed++;
    }

    echo ($held ? '  ok   ' : '  FAIL '), $what, ($saw === '' ? '' : "  — {$saw}"), "\n";
}

$plugin = (new ReflectionClass(Plugin::class))->newInstanceWithoutConstructor();
$screen = (new ReflectionMethod(Plugin::class, 'screen'))->invoke($plugin);

// ── Which pages to look at ───────────────────────────────────────────────────────────────────────
//
// ⚠️ **Three save buttons live on three different panels and one node rarely carries all three.**
// The Labels panel is on every node; `save_field` needs a node that declares attributes; `save_record`
// needs one that holds records. *Chosen from the database rather than named here — a check that names
// node 3988 stops working the day he renames it.*
$pages = [
    'a node that declares attributes' => (int) $wpdb->get_var(
        "SELECT from_id FROM {$prefix}relations WHERE kind <> 'inheritance' GROUP BY from_id ORDER BY COUNT(*) DESC LIMIT 1"
    ),
    'a node that holds records' => (int) $wpdb->get_var(
        "SELECT node_id FROM {$prefix}records GROUP BY node_id ORDER BY COUNT(*) DESC LIMIT 1"
    ),
];

echo "== the pages this check reads ==\n";

foreach ($pages as $why => $id) {
    check($why . ' was found', $id > 0, (string) $id);
}

/**
 * Every control-shaped element on a page, with what it draws and what it wears.
 *
 * ⚠️ *A `<label>` and a `<span>` are in scope because two of them are controls here: the move trigger
 * is a label (a `<button>` inside a form would submit it) and the chooser trigger is a span.*
 *
 * @return list<array{tag: string, class: string, face: string, aria: string, value: string}>
 */
function controlsOf(string $html): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    // ⚠️ **The charset, and it is not decoration — without it this check passes on the broken page.**
    // *`loadHTML()` assumes ISO-8859-1 when the markup does not say otherwise, so the four bytes of the
    // diskette came back as six Latin-1 **letters** — and «has a letter in it» is precisely how this
    // check decides that a button shows words rather than an icon. Measured: the first version reported
    // «all green» against the very call sites it was written to catch.*
    $doc->loadHTML('<html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>');
    libxml_clear_errors();

    $found = [];
    $xpath = new DOMXPath($doc);

    foreach ($xpath->query('//button | //label[contains(@class,"button")] | //span[contains(@class,"button")]') as $node) {
        if (! $node instanceof DOMElement) {
            continue;
        }

        $dashicon = $xpath->query('.//span[contains(@class,"dashicons")]', $node)->item(0);
        $glyph    = $xpath->query('.//span[contains(@class,"' . ControlMarkup::GLYPH_FACE . '")]', $node)->item(0);
        $words    = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');

        $found[] = [
            'tag'   => $node->nodeName,
            'class' => $node->getAttribute('class'),
            'face'  => $dashicon !== null ? 'dashicon' : ($glyph !== null ? 'glyph' : 'words'),
            'aria'  => $dashicon instanceof DOMElement
                ? $dashicon->getAttribute('aria-label')
                : ($glyph instanceof DOMElement ? $glyph->getAttribute('aria-label') : ''),
            'words' => $words,
            'value' => $node->getAttribute('value'),
        ];
    }

    return $found;
}

/**
 * Does this control draw a shape rather than words?
 *
 * ⚠️ **The third arm is the one that catches the fault.** A Dashicon and a named glyph span say so in
 * a class, and a control that says so was never the problem. **A diskette handed in as the label
 * arrives as bare text**, indistinguishable in the markup from `Save` — except that it holds no
 * letter and no digit, which is exactly what makes it read as an icon on screen.
 */
function looksLikeAnIcon(array $control): bool
{
    if ($control['face'] !== 'words') {
        return true;
    }

    return $control['words'] !== '' && preg_match('/[\p{L}\p{N}]/u', $control['words']) !== 1;
}

echo "\n== nothing that looks like an icon wears a box ==\n";

$seen    = 0;
$icons   = 0;
$naked   = [];
$primary = [];
$mute    = [];

foreach ($pages as $why => $id) {
    if ($id <= 0) {
        continue;
    }

    $_GET['taxmod_node'] = (string) $id;

    foreach (controlsOf($screen->render()) as $control) {
        $seen++;

        $classes  = preg_split('/\s+/', $control['class']) ?: [];
        $borderless = in_array(ControlMarkup::ICON_ONLY, $classes, true);

        if (! looksLikeAnIcon($control)) {
            continue;
        }

        $icons++;

        $where = sprintf('%s value=%s face=%s words=«%s»', $why, $control['value'] === '' ? '-' : $control['value'], $control['face'], $control['words']);

        if (! $borderless) {
            $naked[] = $where . ' class=«' . $control['class'] . '»';
        }

        // ⚠️ `button-primary` paints a solid background and the borderless class exists to take one
        // away — the two together are what made the diskette blue among flat neighbours.
        if ($borderless && in_array('button-primary', $classes, true)) {
            $primary[] = $where;
        }

        // ⚠️ **An icon replaces the label, never the accessible name.** *The same four call sites that
        // boxed the button also made the diskette the name of the save button, because the character
        // was handed in where the words belong.*
        //
        // ⚠️ *The name may come from either side — an `aria-label` on the face or plain words in the
        // button — so both are read and only a control with **neither** is mute. The first version of
        // this asked for the `aria-label` alone and reported the move trigger and the chooser trigger,
        // whose names are their own text.*
        $named = $control['aria'] . ' ' . $control['words'];

        if (preg_match('/[\p{L}]/u', $named) !== 1) {
            $mute[] = $where . ' has no name a screen reader can read';
        }
    }
}

unset($_GET['taxmod_node']);

/**
 * How a list of findings is reported.
 *
 * ⚠️ *Capped, because the first red run printed thirty-one identical lines and the reason scrolled off
 * the top. The count is the news; three examples are enough to act on.*
 */
$say = static function (array $found): string {
    if ($found === []) {
        return '';
    }

    return count($found) . ': ' . implode(' | ', array_slice($found, 0, 3))
        . (count($found) > 3 ? ' | …' : '');
};

check('controls were read off the drawn pages', $seen > 0, $seen . ' controls, ' . $icons . ' of them icon-shaped');
check('every icon-shaped control is borderless', $naked === [], $say($naked));
check('no icon-shaped control is also the prominent one', $primary === [], $say($primary));
check('every icon-shaped control keeps a readable name', $mute === [], $say($mute));

echo "\n== the diskette is written once ==\n";

// ⚠️ **The character itself, searched for in the source.** *Five call sites spelled it out and four
// put it in the wrong slot; one owner for the character is what makes the fifth impossible.*
//
// ⚠️ **Strings only, never comments.** *The first version of this reported `ControlMarkup` and
// `NodesScreen`, and both were right to hold the character: they explain in prose what went wrong with
// it. A check that cannot tell code from a docblock forces the explanation out of the file.*
$loose = [];

foreach (['src', 'scripts/dev'] as $where) {
    $dir   = __DIR__ . '/../../' . $where;
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        // The class that owns the character, and this check, which has to name it to look for it.
        if (in_array($file->getFilename(), ['Control.php', 'icon-button-check.php'], true)) {
            continue;
        }

        $body = file_get_contents($file->getPathname());

        if ($body === false || ! str_contains($body, Control::SAVE_GLYPH)) {
            continue;
        }

        foreach (token_get_all($body) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_STRING], true)) {
                continue;
            }

            if (str_contains($token[1], Control::SAVE_GLYPH)) {
                $loose[] = $where . '/' . $file->getFilename() . ':' . $token[2];
            }
        }
    }
}

check('only Control names the diskette', $loose === [], $loose === [] ? '' : implode(', ', $loose));

echo "\n== the size the installation screen offers actually arrives ==\n";

// ⚠️ **Read out of the file, because that is where the fault sat.** *The property was on the wrapper
// as D-397 promised; the rule that drew the glyph hard-coded a number and outweighed the one that used
// it. A check on the option, or on the wrapper's inline style, would have passed on the broken page.*
$css = file_get_contents(__DIR__ . '/../../assets/admin.css');

check('the stylesheet is readable', is_string($css) && $css !== '', is_string($css) ? strlen($css) . ' bytes' : '');

$css = (string) $css;

/** The declarations of one rule, by its selector. */
$bodyOf = static function (string $css, string $selector): string {
    $at = strpos($css, $selector . ' {');

    if ($at === false) {
        return '';
    }

    $open = strpos($css, '{', $at);
    $shut = strpos($css, '}', (int) $open);

    return $open === false || $shut === false ? '' : substr($css, $open + 1, $shut - $open - 1);
};

foreach (['.taxmod-icon-button .dashicons', '.taxmod-icon-button .' . ControlMarkup::GLYPH_FACE] as $selector) {
    $rule = $bodyOf($css, $selector);

    check("«{$selector}» has a rule", $rule !== '');
    check(
        "«{$selector}» takes its size from --taxmod-icon",
        $rule !== '' && str_contains($rule, 'var(--taxmod-icon'),
        trim(preg_replace('/\s+/', ' ', $rule) ?? '')
    );
    check(
        "«{$selector}» states no font-size of its own in pixels",
        $rule !== '' && preg_match('/font-size:\s*\d/', $rule) !== 1
    );
}

// ⚠️ *And the sum itself: **one** place computes how big a drawn icon is, so a region can add to it
// without a second number per panel. Counted rather than found, because two places computing it is the
// same fault as four places composing a button.*
$sums = substr_count($css, '--taxmod-icon-drawn:');

check('exactly one rule computes the drawn size', $sums === 1, $sums . ' declarations');
check(
    'the sum rides on the chosen size',
    preg_match('/--taxmod-icon-drawn:\s*calc\(\s*var\(--taxmod-icon[,)]/', $css) === 1
);

echo "\n";

if ($failed === 0) {
    echo "all green\n";

    exit(0);
}

printf("%d Pruefungen sind rot.\n", $failed);

exit(1);
