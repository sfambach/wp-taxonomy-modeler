<?php declare(strict_types=1);

/**
 * Ein Modul bekommt das Formular eines Satzes über einen Filter und bleibt nach dem Speichern auf seiner Seite (D-1025).
 *
 *     php scripts/dev/satz-bearbeiten-check.php [path/to/wordpress]
 *
 * ⚠️ *Sein Wort am 2026-10-30: «warum springt er zum bearbeiten in den tax wir haben doch die renderer dafür sollte somit kein problem
 * sein es dort zu bearbeiten».*
 */

$wordpress = $argv[1] ?? (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress');

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';

require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\WordPress\Plugin;

global $wpdb;

$p    = $wpdb->prefix . 'taxmod_';
$satz = (int) $wpdb->get_var(
    "SELECT r.id FROM {$p}node_records r JOIN {$p}relations k ON k.from_node_id = r.node_id
      WHERE r.record_type = 'user' ORDER BY r.id DESC LIMIT 1"
);

if ($satz === 0) {
    echo "  FAIL kein Satz an einem Knoten mit Feldern — der Wächter prüft nichts\n";
    exit(1);
}

$zurueck = admin_url('admin.php?page=modul&x=1');
$fehler  = 0;

wp_set_current_user(0);
$ohne = (string) apply_filters(Plugin::RECORD_EDITOR_FILTER, '', $satz, $zurueck);
printf("  %s ohne Recht kein Formular\n", $ohne === '' ? 'ok  ' : 'FAIL');
$fehler += $ohne === '' ? 0 : 1;

wp_set_current_user(1);
$markup = (string) apply_filters(Plugin::RECORD_EDITOR_FILTER, '', $satz, $zurueck);

foreach ([
    'das Formular des Satzes'      => 'id="taxmod-preview-record-' . $satz . '"',
    'Speichern'                    => 'value="save_record"',
    'der Rückweg zum Modul'        => esc_attr($zurueck),
    'kein «Neuer Satz»'            => null,
] as $was => $suche) {
    $gut = $suche === null ? ! str_contains($markup, 'value="add_record"') : str_contains($markup, $suche);
    printf("  %s Satz #%d: %s\n", $gut ? 'ok  ' : 'FAIL', $satz, $was);
    $fehler += $gut ? 0 : 1;
}

exit($fehler === 0 ? 0 : 1);
