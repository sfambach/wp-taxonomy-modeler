<?php declare(strict_types=1);

/**
 * Two faulty fields in one save give two complaints, each with its own address, and none is lost on the way to the page
 * (renderer plan step 5, D-1424, [D-632](../../docs/NewConcept/90-decision-log.md)). Since [D-1554](../../docs/NewConcept/90-decision-log.md) a range
 * error is saved and the complaints are a note, no longer a refusal.
 *
 * ⚠️ *Measured where a person meets it: the save is posted through the node page, the redirect is followed, and the page that
 * comes back is read. Before step 5 both complaints stood in one sentence and nothing said which field each belonged to.*
 *
 *     php scripts/dev/complaint-address-check.php
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Event\Address;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Type\IntType;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;

wp_set_current_user(1);

final class ComplaintStop extends RuntimeException
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
$int    = (new WpdbNodeRepository())->byImplementations([IntType::class])[IntType::class] ?? null;

if ($int === null) {
    echo "  FAIL no integer type in this model\n";
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

$refs = $apply([
    ['op' => 'create_node', 'ref' => 'n', 'name' => '__ca Bauteil', 'parent' => $fw->rootOf(Branch::Model)->id],
    ['op' => 'add_field', 'ref' => 'a', 'node' => '@n', 'target' => $int->id, 'name' => '__ca Menge'],
    ['op' => 'add_field', 'ref' => 'b', 'node' => '@n', 'target' => $int->id, 'name' => '__ca Preis'],
    ['op' => 'create_record', 'ref' => 's', 'node' => '@n'],
]);

foreach (['a', 'b'] as $feld) {
    $apply([
        ['op' => 'set_addons', 'node' => $int->id, 'field' => $refs[$feld], 'addons' => [['name' => 'range']]],
        ['op' => 'set_setting', 'node' => $int->id, 'field' => $refs[$feld], 'attribute' => 'min', 'value' => '3'],
    ]);
}

$umgeleitet = '';

add_filter('wp_redirect', static function (string $location) use (&$umgeleitet): string {
    $umgeleitet = $location;

    throw new ComplaintStop();
});

$knoten = (int) $refs['n'];
$satz   = (int) $refs['s'];

$_POST = wp_slash([
    'action'         => $aktion,
    'id'             => (string) $knoten,
    'do'             => 'save_record',
    'node_record_id' => (string) $satz,
    '_taxmod_nonce'  => wp_create_nonce($aktion . '_' . $knoten),
    'taxmod_value'   => [(string) $satz => [(string) $refs['a'] => '1', (string) $refs['b'] => '2']],
]);
$_REQUEST = $_POST;

ob_start();

try {
    $plugin->screen()->handlePost();
} catch (ComplaintStop) {
}

ob_end_clean();
parse_str((string) parse_url($umgeleitet, PHP_URL_QUERY), $teile);

// D-1554 (concept change, PR-9): «a range error is saved and reported» — before, this save was refused.
echo "== the save is kept with a note, with both complaints ==\n";

$hinweis = strstr(__('Saved with a note: %s', 'taxmod'), '%s', true);
$say(str_starts_with(rawurldecode((string) ($teile['taxmod_message'] ?? '')), (string) $hinweis), 'the save is kept and says why', rawurldecode((string) ($teile['taxmod_message'] ?? '')));
$say(count((new \Taxmod\WordPress\Persistence\WpdbRecordRepository())->valuesOf($satz)) >= 2, 'both values were written');
$say(($teile['taxmod_complaints'] ?? '') !== '', 'the complaints are kept for the next page');

echo "\n== the page that comes back ==\n";

$_POST = [];
$_GET  = array_map(static fn ($v) => is_string($v) ? rawurldecode($v) : $v, $teile);
$seite = $plugin->screen()->render();

preg_match_all('/<li data-taxmod-address="([^"]*)">([^<]*)<\/li>/', $seite, $zeilen, PREG_SET_ORDER);

$erwartet = [
    (new Address($knoten, (int) $refs['a'], $satz))->token(),
    (new Address($knoten, (int) $refs['b'], $satz))->token(),
];
$gefunden = array_map(static fn (array $z): string => $z[1], $zeilen);

$say(count($zeilen) === 2, 'two complaints stand on the page', count($zeilen) . ' found');
$say($gefunden === $erwartet, 'each names its own field and the record', implode(' · ', $gefunden));
$say(count(array_filter($zeilen, static fn (array $z): bool => str_contains($z[2], '3'))) === 2, 'each says its bound');

$_GET = array_map(static fn ($v) => is_string($v) ? rawurldecode($v) : $v, $teile);
$say(! str_contains($plugin->screen()->render(), 'taxmod-complaints'), 'they are shown once, not again on the next load');

echo $failed === 0 ? "\nAll good.\n" : "\n$failed FAILED\n";

exit($failed === 0 ? 0 : 1);
