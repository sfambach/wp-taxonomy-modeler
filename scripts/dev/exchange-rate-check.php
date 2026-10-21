<?php declare(strict_types=1);

/**
 * The frozen rate of D-966 against a real database: the hidden field `kurs` on the unit value, created once, and the
 * rate table answering with the last published day on or before a date (D-069). No network: rows are put in by hand.
 *
 * Usage: php scripts/dev/exchange-rate-check.php C:/Devel/Wordpress
 *
 * @see docs/NewConcept/90-decision-log.md D-966
 */

$wordpress = $argv[1] ?? getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';

define('WP_USE_THEMES', false);

require rtrim($wordpress, '/') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/../../vendor/autoload.php';

use Taxmod\Core\Model\Multiplicity;
use Taxmod\WordPress\Currency\EcbRates;
use Taxmod\WordPress\Currency\RateSchema;
use Taxmod\WordPress\Persistence\UnitScaffold;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Plugin;

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    $ok ? $passed++ : $failed++;
    echo ($ok ? '  ok   ' : '  FAIL ') . $what . ($ok || $detail === '' ? '' : " — {$detail}") . "\n";
}

global $wpdb;

echo "Der Kurs am Einheitenwert\n";

$vorher = get_option(UnitScaffold::RATE_FIELD_OPTION, null);
delete_option(UnitScaffold::RATE_FIELD_OPTION);

$rc = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$scaffold = (new ReflectionMethod($plugin, 'unitScaffold'))->invoke($plugin);
$scaffold->ensureRateField();

$id = UnitScaffold::rateFieldId();
$kante = $id === null ? null : (new WpdbRelationRepository())->byId($id);

check('die Kante ist angelegt und notiert', $kante !== null, (string) $id);
check('am Einheitenwert', $kante?->fromNodeId === UnitScaffold::unitValueId());
check('versteckt', $kante?->hide === true);
check('höchstens einmal', $kante?->multiplicity === Multiplicity::ZeroToOne);

$scaffold->ensureRateField();
check('ein zweiter Lauf legt keine zweite an', UnitScaffold::rateFieldId() === $id);

if ($vorher !== null) {
    update_option(UnitScaffold::RATE_FIELD_OPTION, $vorher, true);
}

echo "\nDie Kurstabelle\n";

RateSchema::upgrade();
$wpdb->query($wpdb->prepare('DELETE FROM ' . RateSchema::table() . ' WHERE currency = %s', 'XTS'));
$wpdb->insert(RateSchema::table(), ['currency' => 'XTS', 'day' => '2026-09-18', 'quote' => '1.1634', 'fetched_at' => '2026-09-18 16:00:00']);
$wpdb->insert(RateSchema::table(), ['currency' => 'XTS', 'day' => '2026-09-21', 'quote' => '1.17', 'fetched_at' => '2026-09-21 16:00:00']);

$rates = new EcbRates();
check('am Tag selbst', $rates->quoteOn('XTS', '2026-09-21') === '1.17');
check('am Sonntag der Freitag davor', $rates->quoteOn('XTS', '2026-09-20') === '1.1634', (string) $rates->quoteOn('XTS', '2026-09-20'));
check('ein ungültiger Code wird nicht gefragt', $rates->quoteOn('usd;', '2026-09-20') === null);

$wpdb->query($wpdb->prepare('DELETE FROM ' . RateSchema::table() . ' WHERE currency = %s', 'XTS'));

echo "\n{$passed} ok, {$failed} rot\n";
exit($failed === 0 ? 0 : 1);
