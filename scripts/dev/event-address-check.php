<?php declare(strict_types=1);

/**
 * Every act on the node page names its place — and no place names a node, edge or record that does not exist.
 *
 * Step 1 of the renderer plan (`docs/pakete/renderer-ereignisse/plan.md`, D-1424): «jede `Complaint` und jedes `Control`
 * auf der Knotenseite trägt eine auflösbare Adresse; keine Adresse zeigt auf einen Knoten, den es nicht gibt.»
 *
 * ⚠️ *Measured on the drawn page, not on the code: an act is a `<button>` whose `name` is `do` or `do[…]`. A `Control` that
 * only carries a word (`word:…`) is no act and is never drawn as a button.*
 *
 *     php scripts/dev/event-address-check.php
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

wp_set_current_user(1);

use Taxmod\Core\Addon\Complaint;
use Taxmod\Core\Event\Address;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Service\DataEntry;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\{Schema, SeededFrameworkNodes, WpdbChangelog, WpdbLabelRepository, WpdbNodeRepository, WpdbRecordRepository, WpdbRelationRepository};
use Taxmod\WordPress\SystemClock;

register_shutdown_function(static fn (): int => Schema::forgetOrphanLabels());

$nodes     = new WpdbNodeRepository();
$relations = new WpdbRelationRepository();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $relations, $log);
$records   = new WpdbRecordRepository();
$editor    = new ModelEditor($nodes, $relations, $framework, $log, new WpdbLabelRepository(), $records);
$data      = new DataEntry($records, $relations, $nodes, $framework, new SystemClock());

$failed = 0;

$say = static function (bool $ok, string $what, string $detail = '') use (&$failed): void {
    printf("  %-4s %s%s\n", $ok ? 'ok' : 'FAIL', $what, $ok || $detail === '' ? '' : ' — ' . $detail);

    if (! $ok) {
        ++$failed;
    }
};

echo "== the address itself ==\n";

$voll = new Address(12, 5, 7, 'min');
$say(Address::fromToken($voll->token())->equals($voll), 'token → address → token is the same place', $voll->token());

$abgewiesen = 0;

foreach (['', 'node=0', 'edge=3', 'node=1;node=2', 'node=1;field=<b>', 'node=01', 'node=1;colour=red'] as $kaputt) {
    try {
        Address::fromToken($kaputt);
    } catch (\InvalidArgumentException) {
        ++$abgewiesen;
    }
}

$say($abgewiesen === 7, 'seven broken tokens are refused', "$abgewiesen of 7");

$beschwerde = (new Complaint('range', 'too_small', ['min' => '3']))->at(Address::ofEdge(12, 5));
$say($beschwerde->address?->token() === 'node=12;edge=5' && $beschwerde->values === ['min' => '3'], 'a complaint carries its place and keeps its placeholders');

echo "\n== the node page ==\n";

$typen = $editor->childrenOf($framework->rootOf(Branch::DataTypes)->id);
$ding  = $editor->createNode('__ea Ding', $framework->rootOf(Branch::Model)->id);
$kind  = $editor->createNode('__ea Kind', $ding->id);
$feld  = $editor->addField($ding->id, $typen[0]->id, '__ea Feld');
$satz  = $data->create($ding->id);

$r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');

global $wpdb;

$gibt = static function (string $tabelle, int $id) use ($wpdb): bool {
    return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::table($tabelle) . ' WHERE id = %d', $id)) === 1;
};

$seiten = [
    'the node'        => ['taxmod_node' => (string) $ding->id, 'taxmod_collapsed' => 'none'],
    'an opened record' => ['taxmod_open_record' => (string) $satz->id, 'taxmod_collapsed' => 'none'],
];

foreach ($seiten as $welche => $get) {
    $_GET   = $get;
    $markup = $plugin->screen()->render();

    preg_match_all('/<button\b[^>]*\bname="do(?:\[[^"]*\])?"[^>]*>/', $markup, $knoepfe);

    $ohne      = [];
    $falsch    = [];
    $adressen  = [];

    foreach ($knoepfe[0] as $knopf) {
        preg_match('/\bvalue="([^"]*)"/', $knopf, $wert);

        if (preg_match('/\bdata-taxmod-address="([^"]*)"/', $knopf, $treffer) !== 1) {
            $ohne[] = $wert[1] ?? '?';

            continue;
        }

        try {
            $adresse = Address::fromToken($treffer[1]);
        } catch (\InvalidArgumentException) {
            $falsch[] = $treffer[1];

            continue;
        }

        $adressen[] = $adresse;

        if (! $gibt('nodes', $adresse->nodeId)
            || ($adresse->edgeId !== null && ! $gibt('relations', $adresse->edgeId))
            || ($adresse->recordId !== null && ! $gibt('node_records', $adresse->recordId))) {
            $falsch[] = $treffer[1];
        }
    }

    printf("       %s: %d acts\n", $welche, count($knoepfe[0]));

    $say(count($knoepfe[0]) > 5, "$welche: the page draws acts at all");
    $say($ohne === [], "$welche: every act carries an address", implode(', ', array_unique($ohne)));
    $say($falsch === [], "$welche: every address resolves", implode(', ', array_unique($falsch)));

    $tokens = array_map(static fn (Address $a): string => $a->token(), $adressen);
    $say(in_array('node=' . $ding->id, $tokens, true), "$welche: the node's own acts name the node");
    $say(in_array('node=' . $ding->id . ';edge=' . $feld->id, $tokens, true), "$welche: the field row's acts name the field's edge");
}

$say(in_array('node=' . $ding->id . ';record=' . $satz->id, $tokens ?? [], true), 'the opened record\'s acts name the record');

echo $failed === 0 ? "\nAll good.\n" : "\n$failed FAILED\n";

exit($failed === 0 ? 0 : 1);
