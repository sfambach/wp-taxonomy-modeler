<?php declare(strict_types=1);

/**
 * «Choice from field» (D-1520): a field that points at records offers only what another field of the same record holds, and a save
 * that chooses anything else is refused — checked against what is sent with the same save, else what is stored.
 *
 * ⚠️ *Measured where a person meets it: the save is posted through the node page and the open record form is read.
 * His case: the workplace's default printer only from its available printers.*
 *
 *     php scripts/dev/choice-from-check.php
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;

wp_set_current_user(1);

final class ChoiceFromStop extends RuntimeException
{
}

$failed = 0;

$say = static function (bool $ok, string $what, string $detail = '') use (&$failed): void {
    printf("  %-4s %s%s\n", $ok ? 'ok' : 'FAIL', $what, $ok || $detail === '' ? '' : ' — ' . $detail);

    if (! $ok) {
        ++$failed;
    }
};

$r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');

$fw     = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin);
$aktion = (string) (new ReflectionClass(NodesScreen::class))->getConstant('ACTION');
$text   = (new WpdbNodeRepository())->byImplementations([TextType::class])[TextType::class] ?? null;

if ($text === null) {
    echo "  FAIL no text type in this model\n";
    exit(1);
}

$apply = static function (array $changes): array {
    $antwort = wp_get_ability('taxmod/apply')->execute(['changes' => $changes]);

    if (is_wp_error($antwort)) {
        echo '  FAIL setup refused: ' . $antwort->get_error_message() . "\n";
        exit(1);
    }

    return $antwort['refs'];
};

$aenderungen = [
    ['op' => 'create_node', 'ref' => 'drucker', 'name' => '__cf Drucker', 'parent' => $fw->rootOf(Branch::Model)->id],
    ['op' => 'add_field', 'ref' => 'name', 'node' => '@drucker', 'target' => $text->id, 'name' => '__cf Name'],
    ['op' => 'create_node', 'ref' => 'platz', 'name' => '__cf Platz', 'parent' => $fw->rootOf(Branch::Model)->id],
    ['op' => 'add_field', 'ref' => 'verfuegbar', 'node' => '@platz', 'target' => '@drucker', 'name' => '__cf Verfügbar', 'kind' => 'aggregation', 'multiplicity' => '0..*'],
    ['op' => 'add_field', 'ref' => 'standard', 'node' => '@platz', 'target' => '@drucker', 'name' => '__cf Standard', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
    ['op' => 'create_record', 'ref' => 's', 'node' => '@platz'],
];

foreach (['alpha' => 'Alpha', 'beta' => 'Beta', 'gamma' => 'Gamma'] as $ref => $wort) {
    $aenderungen[] = ['op' => 'create_record', 'ref' => $ref, 'node' => '@drucker'];
    $aenderungen[] = ['op' => 'set_values', 'record' => '@' . $ref, 'values' => ['@name' => '__cf ' . $wort]];
}

$refs = $apply($aenderungen);
$apply([
    ['op' => 'set_addons', 'node' => $refs['drucker'], 'field' => $refs['standard'], 'addons' => [['name' => 'choice_from', 'fields' => ['from_field' => (string) $refs['verfuegbar']]]]],
]);

$knoten = (int) $refs['platz'];
$satz   = (int) $refs['s'];
$umgeleitet = '';

add_filter('wp_redirect', static function (string $location) use (&$umgeleitet): string {
    $umgeleitet = $location;

    throw new ChoiceFromStop();
});

$speichern = static function (array $werte) use ($plugin, $aktion, $knoten, $satz, &$umgeleitet): string {
    $umgeleitet = '';
    $_POST = wp_slash([
        'action'         => $aktion,
        'id'             => (string) $knoten,
        'do'             => 'save_record',
        'node_record_id' => (string) $satz,
        '_taxmod_nonce'  => wp_create_nonce($aktion . '_' . $knoten),
        'taxmod_value'   => [(string) $satz => $werte],
    ]);
    $_REQUEST = $_POST;

    ob_start();

    try {
        $plugin->screen()->handlePost();
    } catch (ChoiceFromStop) {
    }

    ob_end_clean();
    parse_str((string) parse_url($umgeleitet, PHP_URL_QUERY), $teile);

    return rawurldecode((string) ($teile['taxmod_message'] ?? ''));
};

$gespeichert = static function (int $feld) use ($satz): array {
    global $wpdb;

    return array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT value_ref FROM ' . Schema::table('relation_records') . ' WHERE node_record_id = %d AND relation_id = %d', $satz, $feld)));
};

$verfuegbar = (string) $refs['verfuegbar'];
$standard   = (string) $refs['standard'];

echo "== a save checks the choice against the source sent with it ==\n";

$meldung = $speichern([$verfuegbar => ['values' => [(string) $refs['alpha'], (string) $refs['gamma']]], $standard => (string) $refs['beta']]);
$say(str_starts_with($meldung, 'Not saved'), 'Beta is not available: refused', $meldung);
$say(str_contains($meldung, '__cf Verfügbar'), 'the refusal names the source field', $meldung);
$say($gespeichert((int) $verfuegbar) === [] && $gespeichert((int) $standard) === [], 'nothing of that save is written');

$meldung = $speichern([$verfuegbar => ['values' => [(string) $refs['alpha'], (string) $refs['gamma']]], $standard => (string) $refs['gamma']]);
$say(! str_starts_with($meldung, 'Not saved'), 'Gamma is available: saved', $meldung);
$say($gespeichert((int) $standard) === [(int) $refs['gamma']], 'the default is written', implode(',', $gespeichert((int) $standard)));

echo "\n== without the source in the save, the stored one counts ==\n";

$say(str_starts_with($speichern([$standard => (string) $refs['beta']]), 'Not saved'), 'Beta alone: refused against the stored source');
$say(! str_starts_with($speichern([$standard => (string) $refs['alpha']]), 'Not saved'), 'Alpha alone: saved');

echo "\n== the open record offers only what the source holds ==\n";

$_POST = [];
$_GET  = ['page' => 'taxmod-nodes', 'taxmod_node' => (string) $knoten, 'taxmod_preview_record' => (string) $satz];
$seite = $plugin->screen()->render();
$_GET  = [];
$an     = strpos($seite, 'taxmod_value[' . $satz . '][' . $standard . ']');
$stueck = $an === false ? '' : substr($seite, $an, 6000);
// *The tree of a chooser stands once as a shared template (D-866); its stub, right before the field's name, carries the id.*
$davor = $an === false ? '' : substr($seite, max(0, $an - 400), 400);
if (preg_match_all('/data-taxmod-body="([^"]+)"/', $davor, $ids) > 0
    && preg_match('/<template class="taxmod-shared-body" data-taxmod-body="' . preg_quote((string) end($ids[1]), '/') . '">(.*?)<\/template>/s', $seite, $t) === 1) {
    $stueck = $t[1];
}

$say($stueck !== '', 'the default field is drawn');
$say(str_contains($stueck, '__cf Alpha') && str_contains($stueck, '__cf Gamma'), 'Alpha and Gamma are offered');
$say(! str_contains($stueck, '__cf Beta'), 'Beta is not offered');

echo $failed === 0 ? "\nAll good.\n" : "\n$failed FAILED\n";

exit($failed === 0 ? 0 : 1);
