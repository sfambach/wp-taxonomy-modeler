<?php declare(strict_types=1);

/**
 * `set_values` with a field the record's node does not carry is refused with the field named — never a success that wrote nothing.
 * Prüftor 19.11.: «Er soll mit Grund abgelehnt werden statt still durchzulaufen, und ein Wächter gehört dazu.» Found by the D-1487
 * migration run, whose first try wrote to a field id of the live site on a replica.
 *
 * ```text
 * CONTRACT
 * unknown field   taxmod/apply answers an error naming the field; no value is written
 * known field     the same call with the node's own field writes, as before
 * ```
 *
 * Usage: php scripts/dev/unknown-field-check.php
 *
 * @see docs/NewConcept/90-decision-log.md D-1488
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/lib/beispiel.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;

wp_set_current_user(1);

$failed = 0;
$say    = static function (bool $ok, string $what, string $detail = '') use (&$failed): void {
    printf("  %-4s %s%s\n", $ok ? 'ok' : 'FAIL', $what, $ok || $detail === '' ? '' : ' — ' . $detail);
    $failed += $ok ? 0 : 1;
};

$text = taxmodSaatKnoten(Branch::DataTypes, 'Text');
$refs = taxmodBeispiel([
    ['op' => 'create_node', 'ref' => 'k', 'name' => '__uf Knoten', 'parent' => 402],
    ['op' => 'add_field', 'ref' => 'f', 'node' => '@k', 'target' => $text, 'name' => '__uf Name', 'kind' => 'composition', 'multiplicity' => '0..1'],
    ['op' => 'create_node', 'ref' => 'a', 'name' => '__uf Anderer', 'parent' => 402],
    ['op' => 'add_field', 'ref' => 'g', 'node' => '@a', 'target' => $text, 'name' => '__uf Fremd', 'kind' => 'composition', 'multiplicity' => '0..1'],
    ['op' => 'create_record', 'ref' => 'r', 'node' => '@k'],
]);

global $wpdb;
$werte = static fn (): int => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}taxmod_relation_records WHERE node_record_id = %d", $refs['r']));

$apply = wp_get_ability('taxmod/apply');
$nein  = $apply->execute(['changes' => [['op' => 'set_values', 'record' => (string) $refs['r'], 'values' => [(string) $refs['g'] => 'x']]]]);
$say(is_wp_error($nein) && str_contains($nein->get_error_message(), (string) $refs['g']), 'ein fremdes Feld wird mit seiner Id abgelehnt', is_wp_error($nein) ? $nein->get_error_message() : 'kein Fehler');
$say($werte() === 0, 'und nichts ist geschrieben');

$ja = $apply->execute(['changes' => [['op' => 'set_values', 'record' => (string) $refs['r'], 'values' => [(string) $refs['f'] => 'x']]]]);
$say(! is_wp_error($ja) && $werte() === 1, 'das eigene Feld schreibt wie bisher', is_wp_error($ja) ? $ja->get_error_message() : '');

echo $failed === 0 ? "unknown-field-check: ok\n" : "unknown-field-check: {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
