<?php declare(strict_types=1);

/**
 * Ein Wähler in einem Dialog steht immer an Ort und Stelle, ohne zweiten Dialog (D-1467, ersetzt D-1444/D-1445 für Dialoge); außerhalb
 * eines Dialogs ist er das Feld mit dem Baumknopf (D-1163). Ein Baum an Ort und Stelle hat keinen Öffner.
 *
 * ⚠️ *Sein Wort 2026-11-18 22:49: «in dialogen bitte immer inline sonst machen wir dialog kaskade».* Frühere Fassung (D-1445):
 *
 * ⚠️ *Sein Befund 2026-11-18 12:25: «würde erwarten das hier der tax chooser nicht inline sind wir haben ja den button».* Das
 * Stylesheet stellte jeden Wähler in einem Dialog als Baum dar und verbarg Feld und Knopf — der Aufrufer konnte es nicht abstellen
 * (D-1444 wirkte darum nicht). Geprüft wird:
 *
 * ```text
 * CONTRACT
 * Stylesheet   in einem Dialog Feld mit Baumknopf, die Liste klappt darunter auf (D-1488, ersetzt die Fassung von D-1467)
 * Stylesheet   die Tafel steht ohne transform (sonst wäre sie der Rahmen eines Dialogs in ihr)
 * inline       der Baum steht da, ohne Öffner, ohne Baumknopf, ohne zweiten Dialog
 * nicht inline Feld + Baumknopf, der den eigenen Dialog öffnet
 * ```
 *
 * Usage: php scripts/dev/chooser-in-dialog-check.php
 *
 * @see docs/NewConcept/90-decision-log.md D-1467, D-1445
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

wp_set_current_user(1);

$failed = 0;
$say    = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);
    $failed += $ok ? 0 : 1;
};

echo "\n== Stylesheet ==\n";
$css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin.css');
// D-1488 ersetzt die Fassung «Baum steht immer da, Öffner und Baumknopf verborgen» (D-1467): im Dialog bleibt der Wähler an Ort und
// Stelle, aber als Feld mit dem Baumknopf darin; die Liste klappt mit dem Schalter darunter auf, ohne Mindestbreite.
$say(preg_match('/\.taxmod-dialog-panel \.taxmod-chooser > \.taxmod-dialog-open,\s*\.taxmod-dialog-panel \.taxmod-chooser > \.taxmod-pick-tree,[^{]*\{\s*display:\s*none;/', $css) !== 1
    && preg_match('/\.taxmod-dialog-panel \.taxmod-chooser > \.taxmod-dialog \{\s*display:\s*block;/', $css) !== 1
    && preg_match('/\.taxmod-dialog-panel \.taxmod-chooser > \.taxmod-dialog > \.taxmod-dialog-panel \{[^}]*min-width: 0;/', $css) === 1, 'D-1488: im Dialog Feld mit Baumknopf, die Liste klappt darunter auf, ohne Mindestbreite');
$say(preg_match('/\.taxmod-dialog-panel\s*\{[^}]*transform\s*:/', $css) !== 1, 'die Tafel steht ohne transform');

echo "\n== Wähler ==\n";
global $wpdb;
$node = (int) $wpdb->get_var("SELECT node_id FROM {$wpdb->prefix}taxmod_node_records GROUP BY node_id ORDER BY COUNT(*) DESC, node_id LIMIT 1");
$say($node > 0, 'ein Knoten mit Sätzen zum Prüfen' . ($node > 0 ? ' (#' . $node . ')' : ' — keiner da, die Prüfung prüft nichts'));

if ($node > 0) {
    $inline = (string) apply_filters('taxmod_record_chooser', '', ['name' => 'cd-inline', 'node' => $node, 'inline' => true]);
    $dialog = (string) apply_filters('taxmod_record_chooser', '', ['name' => 'cd-dialog', 'node' => $node]);

    $say(str_contains($inline, 'taxmod-chooser-tree'), 'inline: der Baum steht da');
    $say(! str_contains($inline, 'taxmod-dialog-open') && ! str_contains($inline, 'taxmod-pick-tree') && ! str_contains($inline, 'taxmod-dialog-switch'), 'inline: kein Öffner, kein Baumknopf, kein zweiter Dialog');
    $say(str_contains($dialog, 'taxmod-pick-tree') && str_contains($dialog, 'taxmod-dialog-switch'), 'nicht inline: Feld mit Baumknopf und eigenem Dialog');
}

// D-1470, his screenshot 18.11. 19:52: after choosing in a tree standing in a dialog the dialog fell apart — the focused row
// scrolled the panel itself, title and field out of sight. The panel clips; only its body scrolls.
$css = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/admin.css');
$say((bool) preg_match('/\.taxmod-dialog-panel \{[^}]*overflow: clip;/s', $css), 'D-1470: der Dialog selbst scrollt nie (overflow: clip)');
// D-1470: a dialog opened from the bulk choice is opened like any other (change), so it remembers, takes the focus and shows
// the chosen row of a tree standing in it; an act that redraws the page redraws a module's side column and its tree too.
$js = static fn (string $file): string => (string) file_get_contents(dirname(__DIR__, 2) . '/assets/' . $file);
$say(str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/src/WordPress/Admin/ListPage.php'), "w.dispatchEvent(new Event('change',{bubbles:true}))"), 'D-1470: die Sammelwahl öffnet ihren Dialog mit change');
$say(str_contains($js('admin.js'), '.taxmod-chooser-tree\' ).forEach') && str_contains($js('admin.js'), 'liste.scrollTop'), 'D-1470: ein Baum im Dialog zeigt beim Öffnen die gewählte Zeile');
$say(str_contains($js('live.js'), "[data-taxmod-morph][id]") && str_contains($js('side-tree.js'), "addEventListener( 'taxmod:replaced', start )"), 'D-1470: ein Akt zeichnet auch die Seitenspalte (data-taxmod-morph) und ihren Baum neu');

// His screenshots 2026-11-19 05:47/05:49: in a dialog the tree's search sits at the top of its list and the tree takes the field's
// width; a click on the group already chosen in a module's side tree opens it again, and the page starts at its top; a table
// without rows repeats no head below.
$say((bool) preg_match('/\.taxmod-dialog-panel \.taxmod-chooser > \.taxmod-dialog \.taxmod-chooser-tree \{\s*padding-top: 0;/', $css), '19.11.: das Suchfeld steht im Dialogbaum oben, nichts darüber');
$say(str_contains($js('admin.js'), 'feld.defaultChecked') && str_contains($js('admin.js'), "setItem( 'taxmod.sprung', '1' )"), '19.11.: ein Klick auf die schon gewählte Gruppe öffnet sie, die Seite beginnt oben');
$leer = \Taxmod\WordPress\Admin\ListPage::table([['A']], '<tr class="no-items"><td>x</td></tr>');
$voll = \Taxmod\WordPress\Admin\ListPage::table([['A']], '<tr><td>x</td></tr>');
$say(! str_contains($leer, '<tfoot>') && str_contains($voll, '<tfoot>'), '19.11.: eine Tabelle ohne Zeilen zeigt ihren Kopf nur einmal');

echo $failed === 0 ? "\nall green\n" : "\n{$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
