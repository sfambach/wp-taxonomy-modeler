<?php declare(strict_types=1);

/**
 * Ein Wähler im Dialog ist das Feld mit dem Baumknopf wie überall; ein Baum an Ort und Stelle hat keinen Öffner (D-1445).
 *
 * ⚠️ *Sein Befund 2026-11-18 12:25: «würde erwarten das hier der tax chooser nicht inline sind wir haben ja den button».* Das
 * Stylesheet stellte jeden Wähler in einem Dialog als Baum dar und verbarg Feld und Knopf — der Aufrufer konnte es nicht abstellen
 * (D-1444 wirkte darum nicht). Geprüft wird:
 *
 * ```text
 * CONTRACT
 * Stylesheet   keine Regel verbirgt Öffner oder Baumknopf eines Wählers in einem Dialog
 * Stylesheet   die Tafel steht ohne transform (sonst wäre sie der Rahmen eines Dialogs in ihr)
 * inline       der Baum steht da, ohne Öffner, ohne Baumknopf, ohne zweiten Dialog
 * nicht inline Feld + Baumknopf, der den eigenen Dialog öffnet
 * ```
 *
 * Usage: php scripts/dev/chooser-in-dialog-check.php
 *
 * @see docs/NewConcept/90-decision-log.md D-1445
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
$say(preg_match('/\.taxmod-dialog-panel[^{]*\.taxmod-chooser\s*>\s*\.taxmod-(dialog-open|pick-tree)/', $css) !== 1, 'keine Regel verbirgt Öffner oder Baumknopf eines Wählers im Dialog');
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

echo $failed === 0 ? "\nall green\n" : "\n{$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
