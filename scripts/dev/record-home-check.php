<?php declare(strict_types=1);

/**
 * Ein Satz, den ein Modul führt, öffnet sich auf der Seite dieses Moduls — an jeder Stelle, an der der Modeller auf ihn verlinkt
 * ([D-1483](../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *Sein Wort 2026-11-19 14:10 an der Bestellung #70231: «kein link».* Geprüft wird:
 *
 * ```text
 * CONTRACT
 * Haken        taxmod_record_home (url|null, recordId, nodeId); null oder leer lässt die Modeller-Adresse
 * Verweis      ein angezeigter Satzverweis führt zur Adresse des Hakens, mit dem Knoten des Verweisziels
 * Kopf         die Vorschau eines geführten Satzes trägt «Open in its module» mit dieser Adresse
 * Verwiesen    «Referred to by» führt zur Adresse des Hakens, mit dem Knoten des verweisenden Feldes
 * ohne Haken   alles wie vorher: taxmod_open_record, kein Kopf
 * ```
 *
 * Usage: php scripts/dev/record-home-check.php
 *
 * @see docs/NewConcept/90-decision-log.md D-1483
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Plugin;

global $wpdb;
wp_set_current_user(1);

$failed = 0;
$say    = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);
    $failed += $ok ? 0 : 1;
};

$p = $wpdb->prefix . 'taxmod_';

// *Ein Satz, der auf einen anderen verweist, und der andere — der erste, den die Tabellen hergeben.*
$suche = static fn (): ?object => $wpdb->get_row(
    "SELECT v.node_record_id AS von, a.node_id AS vonKnoten, v.value_ref AS ziel, z.node_id AS zielKnoten, k.from_node_id AS feldKnoten, k.to_node_id AS feldZiel
       FROM {$p}relation_records v
       JOIN {$p}relations k ON k.id = v.relation_id AND k.hide = 0
       JOIN {$p}node_records a ON a.id = v.node_record_id AND a.record_type = 'user'
       JOIN {$p}node_records z ON z.id = v.value_ref AND z.record_type = 'user'
      WHERE v.value_ref_kind = 'record'
      ORDER BY v.id LIMIT 1"
);

$fund = $suche();

if ($fund === null) {
    require_once __DIR__ . '/lib/beispiel.php';

    taxmodBeispiel([
        ['op' => 'create_node', 'ref' => 'ziel', 'name' => '__rh Ziel', 'parent' => 402],
        ['op' => 'create_node', 'ref' => 'von', 'name' => '__rh Von', 'parent' => 402],
        ['op' => 'add_field', 'ref' => 'feld', 'node' => '@von', 'target' => '@ziel', 'name' => '__rh Verweis', 'kind' => 'association', 'multiplicity' => '0..1'],
        ['op' => 'create_record', 'ref' => 'z', 'node' => '@ziel'],
        ['op' => 'create_record', 'ref' => 'v', 'node' => '@von'],
        ['op' => 'set_values', 'record' => '@v', 'values' => ['@feld' => ['record' => '@z']]],
    ]);

    $fund = $suche();
}

if ($fund === null) {
    echo "  FAIL kein Satzverweis gefunden — der Wächter prüft nichts\n";
    exit(1);
}

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');

$zeige = static function (int $knoten, int $satz) use ($plugin): string {
    $_GET     = ['page' => 'taxmod', 'taxmod_node' => (string) $knoten, 'taxmod_open_record' => (string) $satz];
    $_REQUEST = $_GET;

    return $plugin->screen()->render();
};

$say(NodesScreen::RECORD_HOME_FILTER === 'taxmod_record_home', 'der Haken heisst taxmod_record_home');

$gefragt = [];
$haken   = static function (?string $url, int $satz, int $knoten) use (&$gefragt, $fund): ?string {
    $gefragt[$satz][] = $knoten;

    return in_array($satz, [(int) $fund->ziel, (int) $fund->von], true) ? 'https://example.test/home?r=' . $satz : $url;
};
add_filter(NodesScreen::RECORD_HOME_FILTER, $haken, 10, 3);

$von = $zeige((int) $fund->vonKnoten, (int) $fund->von);
$say(str_contains($von, 'href="https://example.test/home?r=' . (int) $fund->ziel . '"'), sprintf('Satz #%d: der Verweis auf #%d führt zur Seite des Moduls', $fund->von, $fund->ziel));
$say(in_array((int) $fund->feldZiel, $gefragt[(int) $fund->ziel] ?? [], true), sprintf('der Haken bekam den Knoten des Verweisziels (%d)', $fund->feldZiel));
$say(str_contains($von, 'class="taxmod-record-home"') && str_contains($von, 'https://example.test/home?r=' . (int) $fund->von), 'die Vorschau des geführten Satzes trägt den Kopf «Open in its module»');

$ziel = $zeige((int) $fund->zielKnoten, (int) $fund->ziel);
$say(preg_match('/taxmod-referrers.*href="https:\/\/example\.test\/home\?r=' . (int) $fund->von . '"/s', $ziel) === 1, sprintf('«Referred to by» an #%d führt zur Seite des Moduls von #%d', $fund->ziel, $fund->von));
$say(in_array((int) $fund->feldKnoten, $gefragt[(int) $fund->von] ?? [], true), sprintf('der Haken bekam den Knoten des verweisenden Feldes (%d)', $fund->feldKnoten));

remove_filter(NodesScreen::RECORD_HOME_FILTER, $haken, 10);
add_filter(NodesScreen::RECORD_HOME_FILTER, static fn (): string => '', 10, 3);

$ohne = $zeige((int) $fund->vonKnoten, (int) $fund->von);
$say(! str_contains($ohne, 'example.test') && ! str_contains($ohne, 'class="taxmod-record-home"'), 'ein leerer Haken lässt die Modeller-Adresse und keinen Kopf');
$say(str_contains($ohne, 'taxmod_open_record=' . (int) $fund->ziel), 'ohne Haken führt der Verweis in den Modeller');

echo $failed === 0 ? "record-home-check: ok\n" : "record-home-check: {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
