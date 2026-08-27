<?php declare(strict_types=1);

/**
 * The preview, and the two flags it exists to make visible — `PR-9`.
 *
 * ⚠️ **The owner's reason for wanting it built now** is the reason this check is worth having:
 * *we need the preview to fix the flag and renderer concept errors.* `hide` and `read_only` were
 * stored, resolved and **had no surface that showed them doing anything** — a settings panel draws
 * the switch, never its effect. So this check does not merely assert that a panel appears; it
 * **flips each flag and asserts the effect**, which is the only version that would catch the fault
 * he is looking for.
 *
 * ⚠️ *It writes settings into the real database and takes them out again. Every earlier version of
 * this pattern littered the tree — a new attribute and nine records per run, which the owner saw —
 * so the cleanup runs whatever happens.*
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require 'C:/Devel/Wordpress/wp-load.php';
require __DIR__ . '/../../vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

use Taxmod\Core\Model\SettingKey;
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

/** Renders one node's detail page and returns only the preview band. */
function previewOf(object $screen, int $nodeId): string
{
    $_GET['taxmod_node'] = $nodeId;

    $html = $screen->render();
    $at   = strpos($html, 'taxmod-preview');

    if ($at === false) {
        return '';
    }

    $band = substr($html, $at);
    $end  = strpos($band, 'taxmod-page-block');

    return $end === false ? $band : substr($band, 0, $end);
}

// ── A model that holds data, and one that cannot ─────────────────────────────────────────────────

echo "== the preview appears where records are possible, and not elsewhere ==\n";

$model = (int) $wpdb->get_var("SELECT node_id FROM {$prefix}records GROUP BY node_id ORDER BY COUNT(*) DESC LIMIT 1");

check('a model with records was found to test against', $model > 0, (string) $model);

$band = previewOf($screen, $model);

check('the preview band is drawn', $band !== '', strlen($band) . ' bytes');
check('both sides are drawn', substr_count($band, 'taxmod-preview-side') === 2, (string) substr_count($band, 'taxmod-preview-side'));

// ⚠️ **Provenance is asserted, not assumed.** A good-looking preview over sample values reads as
// proof that the model holds real ones, which is the opposite of what a preview is for.
check('it says where the values came from', str_contains($band, 'Filled from'));

// A data type describes something rather than being one, so it has nothing to preview.
$dataType = (int) $wpdb->get_var("SELECT id FROM {$prefix}nodes WHERE name = 'int' LIMIT 1");

if ($dataType > 0) {
    $_GET['taxmod_node'] = $dataType;
    $whole = $screen->render();

    check('a data type is told there is nothing to preview', str_contains($whole, 'Nothing to preview here'));
}

// ── The flags, which is the point ────────────────────────────────────────────────────────────────

echo "\n== hide removes a row from the preview and says which ==\n";

$edge = $wpdb->get_row(
    $wpdb->prepare("SELECT id, name FROM {$prefix}relations WHERE from_id = %d AND name <> '' LIMIT 1", $model),
    ARRAY_A
);

if ($edge === null) {
    echo "  --   no named attribute on that model; the flag half is not exercised\n";
} else {
    $edgeId = (int) $edge['id'];
    $name   = (string) $edge['name'];

    /** Puts one flag on the edge, or clears both. */
    $flag = static function (?string $key) use ($wpdb, $prefix, $edgeId): void {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$prefix}settings WHERE owner_id = %d AND setting_key IN ('hide', 'read_only')",
            $edgeId
        ));

        if ($key !== null) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$prefix}settings (owner_id, setting_key, value_int) VALUES (%d, %s, 1)",
                $edgeId,
                $key
            ));
        }
    };

    try {
        $flag(null);
        $plain = previewOf($screen, $model);

        check("with no flag, «{$name}» is drawn", str_contains($plain, $name));
        check('and nothing is reported as left out', ! str_contains($plain, 'Left out by hide'));

        $flag(SettingKey::Hide->value);
        $hidden = previewOf($screen, $model);

        // ⚠️ **Named rather than silently absent.** *A preview that quietly drops a field cannot be
        // told apart from one that forgot it* — and the owner is using this screen to judge whether
        // `hide` is right, so what it removed has to be legible.
        check('with hide, it is reported as left out', str_contains($hidden, 'Left out by hide'));
        check('and the name appears in that report', (bool) preg_match('#Left out by hide:[^<]*' . preg_quote($name, '#') . '#', $hidden));

        echo "\n== read_only keeps the row and refuses the edit ==\n";

        $flag(SettingKey::ReadOnly->value);
        $fixed = previewOf($screen, $model);

        check('with read_only, the row is still drawn', str_contains($fixed, $name));
        check('and it is reported as read-only', str_contains($fixed, 'read-only'));

        // ⚠️ **This is the assertion that distinguishes the two flags.** Collapsing them would make a
        // read-only field invisible, which is the opposite of what it is for.
        check('read_only does not hide anything', ! str_contains($fixed, 'Left out by hide'));
    } finally {
        // ⚠️ Runs even on a failed assertion, because a check that leaves settings behind changes
        // what the next run measures.
        $flag(null);
    }
}

echo "\n== hide puts the renderer out of force (D-399) ==\n";

$node = (int) $wpdb->get_var("SELECT id FROM {$prefix}nodes WHERE name = 'yotta' LIMIT 1");

if ($node === 0) {
    echo "  --   no scaffolded node to test against\n";
} else {
    /** Reads the renderer `<select>` out of the node's own settings panel. */
    $rendererControl = static function (object $screen, int $node): string {
        $_GET['taxmod_node']   = $node;
        $_GET['taxmod_hidden'] = '1';

        preg_match('#<select[^>]*name="[^"]*\[renderer\][^"]*"[^>]*>#', $screen->render(), $m);

        return $m[0] ?? '';
    };

    try {
        $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}settings WHERE owner_id = %d AND setting_key = 'hide'", $node));

        $control = $rendererControl($screen, $node);

        check('the renderer control is found at all', $control !== '');
        check('and it is editable while nothing is hidden', $control !== '' && ! str_contains($control, 'disabled'));

        $wpdb->query($wpdb->prepare("INSERT INTO {$prefix}settings (owner_id, setting_key, value_int) VALUES (%d, 'hide', 1)", $node));

        // ⚠️ **The owner's words are the specification**: *`hide` would have to put the renderer out of
        // force — so no renderer is valid, because it is not used here; the field should then be
        // greyed out.* Greyed and **not removed**: a control that vanishes when a switch is thrown
        // makes a person hunt for the row they were about to use.
        $control = $rendererControl($screen, $node);

        check('with hide, the renderer control is greyed out', str_contains($control, 'disabled'));
        check('and it is still present rather than removed', $control !== '');
    } finally {
        $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}settings WHERE owner_id = %d AND setting_key = 'hide'", $node));
    }
}

echo "\n== what is honestly not there yet ==\n";
echo "  The middle rung of D-160's fallback is missing: real data → *rows marked as test data* →\n";
echo "  the type's sample value. `taxmod_records` has no flag column (C28, list row 17), so this\n";
echo "  preview uses the first record or the defaults and says which.\n";

echo "\n", $failed === 0 ? "all green\n" : "{$failed} failed\n";

exit($failed === 0 ? 0 : 1);
