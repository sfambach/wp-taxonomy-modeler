<?php declare(strict_types=1);

/**
 * «Plattform» einer Bestellung wird ein Verweis auf eine Firma ([D-1487](../../docs/NewConcept/90-decision-log.md)) — der Import
 * löst den Namen der Quelle auf, vorher wie nachher.
 *
 * ⚠️ *Erst geht der Code live, dann die Daten: in der Zwischenzeit ist das Feld noch Text. Geprüft wird beides:*
 *
 * ```text
 * CONTRACT
 * Text         ein Feld mit Datentyp: der Name wird geschrieben und gefunden wie bisher
 * Verweis      ein Feld ohne Datentyp: die Firmen sind die Sätze seines Ziels, je ihr eigenes Wort
 * Finden       «aliexpress»|Nummer findet die Bestellung, die auf die Firma «AliExpress» zeigt
 * Schreiben    eine neue Bestellung bekommt die Id der Firma; ein Name ohne Firma lässt das Feld leer
 * Vergleich    gleiche Firma ist kein Unterschied; ein leeres Feld wird mit der Firma gefüllt
 * ```
 *
 * Usage: php scripts/dev/platform-firm-check.php
 *
 * @see docs/NewConcept/90-decision-log.md D-1487
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/lib/beispiel.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Import\ChangeListBuilder;
use Taxmod\Core\Import\OrderComparison;
use Taxmod\Core\Import\OrderProposal;
use Taxmod\Core\Import\ProposalKind;
use Taxmod\Core\Import\ProposalLine;
use Taxmod\Core\Import\TargetModel;
use Taxmod\Core\Model\Branch;
use Taxmod\WordPress\Import\ModelGateway;

wp_set_current_user(1);

$failed = 0;
$say    = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);
    $failed += $ok ? 0 : 1;
};

$text = taxmodSaatKnoten(Branch::DataTypes, 'Text');
$r    = taxmodBeispiel([
    ['op' => 'create_node', 'ref' => 'firma', 'name' => '__pf Firma', 'parent' => 402],
    ['op' => 'add_field', 'ref' => 'name', 'node' => '@firma', 'target' => $text, 'name' => '__pf Name', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
    ['op' => 'create_node', 'ref' => 'best', 'name' => '__pf Bestellung', 'parent' => 402],
    ['op' => 'create_node', 'ref' => 'kauf', 'name' => '__pf Kauf', 'parent' => 402],
    ['op' => 'add_field', 'ref' => 'nr', 'node' => '@best', 'target' => $text, 'name' => '__pf Nummer', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
    ['op' => 'add_field', 'ref' => 'plat', 'node' => '@best', 'target' => '@firma', 'name' => '__pf Plattform', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
    ['op' => 'add_field', 'ref' => 'ptext', 'node' => '@best', 'target' => $text, 'name' => '__pf Plattform als Text', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
    ['op' => 'add_field', 'ref' => 'haendler', 'node' => '@best', 'target' => $text, 'name' => '__pf Händler', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
    ['op' => 'add_field', 'ref' => 'datum', 'node' => '@best', 'target' => $text, 'name' => '__pf Datum', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
    ['op' => 'add_field', 'ref' => 'titel', 'node' => '@best', 'target' => $text, 'name' => '__pf Titel', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
    ['op' => 'add_field', 'ref' => 'ktitel', 'node' => '@kauf', 'target' => $text, 'name' => '__pf Kauftitel', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
    ['op' => 'add_field', 'ref' => 'pos', 'node' => '@best', 'target' => '@kauf', 'name' => '__pf Positionen', 'kind' => 'aggregation', 'multiplicity' => '0..*'],
    ['op' => 'create_record', 'ref' => 'mouser', 'node' => '@firma'],
    ['op' => 'set_values', 'record' => '@mouser', 'values' => ['@name' => 'Mouser Electronics']],
    ['op' => 'create_record', 'ref' => 'paypal', 'node' => '@firma'],
    ['op' => 'set_values', 'record' => '@paypal', 'values' => ['@name' => 'PayPal']],
    ['op' => 'create_record', 'ref' => 'conrad1', 'node' => '@firma'],
    ['op' => 'set_values', 'record' => '@conrad1', 'values' => ['@name' => 'Conrad Electronic']],
    ['op' => 'create_record', 'ref' => 'conrad2', 'node' => '@firma'],
    ['op' => 'set_values', 'record' => '@conrad2', 'values' => ['@name' => 'Conrad Electronic SE']],
    ['op' => 'create_record', 'ref' => 'ali', 'node' => '@firma'],
    ['op' => 'set_values', 'record' => '@ali', 'values' => ['@name' => 'AliExpress']],
    ['op' => 'create_record', 'ref' => 'ebay', 'node' => '@firma'],
    ['op' => 'set_values', 'record' => '@ebay', 'values' => ['@name' => 'eBay']],
    ['op' => 'create_record', 'ref' => 'b1', 'node' => '@best'],
    ['op' => 'set_values', 'record' => '@b1', 'values' => ['@nr' => 'A-1', '@plat' => '@ali', '@ptext' => 'AliExpress']],
    ['op' => 'create_record', 'ref' => 'b2', 'node' => '@best'],
    ['op' => 'set_values', 'record' => '@b2', 'values' => ['@nr' => 'E-2']],
]);

$ziel = static fn (string $plattform): TargetModel => new TargetModel($r['best'], $r['kauf'], 0, 0, 0, [
    'title' => $r['titel'], 'order_number' => $r['nr'], 'platform' => $r[$plattform], 'merchant' => $r['haendler'], 'ordered_at' => $r['datum'], 'lines' => $r['pos'],
], ['title' => $r['ktitel']], ['EUR' => 8571]);

// Text: wie bisher.
$tor = new ModelGateway($ziel('ptext'));
$say(($tor->findOrder('aliexpress', 'A-1'))?->recordId === $r['b1'], 'Text: «aliexpress»|A-1 findet die Bestellung');
$say($tor->writingTarget() instanceof TargetModel && $tor->writingTarget()->platformFirms === null, 'Text: keine Firmen, der Name wird geschrieben');

// Verweis.
$tor  = new ModelGateway($ziel('plat'));
$fund = $tor->findOrder('ALIEXPRESS', 'A-1');
$say($fund?->recordId === $r['b1'], 'Verweis: «ALIEXPRESS»|A-1 findet die Bestellung, die auf die Firma zeigt');
$say(($fund?->values['platform'] ?? null) === 'AliExpress', 'Verweis: die Bestellung nennt das Wort der Firma');
$say($tor->findOrder('PayPal', 'A-1') === null, 'Verweis: ein Name ohne Firma findet nichts unter fremder Firma');
$say($tor->findOrder('eBay', 'E-2')?->recordId === $r['b2'], 'Verweis: eine Bestellung ohne Plattform wird über D-1405 gefunden');

$schreiben = $tor->writingTarget();
$say($schreiben instanceof TargetModel && $schreiben->platformValue('ebay') === (string) $r['ebay'], 'Verweis: «ebay» wird als Id der Firma geschrieben');

$leer = $tor->findOrder('eBay', 'E-2');
$say($leer !== null && OrderComparison::fills(new OrderProposal('eBay', 'E-2', ProposalKind::Ordered), $leer) === ['platform'], 'Verweis: ein leeres Feld wird mit der Firma gefüllt');
$say($fund !== null && OrderComparison::between(new OrderProposal('aliexpress', 'A-1', ProposalKind::Ordered), $fund) === [], 'Verweis: dieselbe Firma ist kein Unterschied');

$fuellen = $leer === null ? [] : (new ChangeListBuilder($schreiben))->completed($leer, new OrderProposal('eBay', 'E-2', ProposalKind::Ordered), ['platform']);
$antwort = wp_get_ability('taxmod/write-data')->execute(['changes' => $fuellen]);
$say($fuellen !== [] && ! is_wp_error($antwort), 'Verweis: die Firma wird geschrieben' . (is_wp_error($antwort) ? ': ' . $antwort->get_error_message() : ''));
$say((new ModelGateway($ziel('plat')))->findOrder('EBAY', 'E-2')?->values['platform'] === 'eBay', 'Verweis: die Bestellung zeigt danach auf die Firma «eBay»');

// Prüftor 2026-11-19: the readers say «Mouser», the firm is «Mouser Electronics» — one firm beginning with the name names it.
$say($schreiben instanceof TargetModel && $schreiben->platformValue('Mouser') === (string) $r['mouser'], 'Verweis: «Mouser» nennt «Mouser Electronics» (eine Firma beginnt damit)');
$say($schreiben instanceof TargetModel && $schreiben->platformValue('Conrad') === null, 'Verweis: «Conrad» nennt keine, zwei Firmen beginnen damit');

// Prüftor 2026-11-19: a second import run makes no second order — for a firm by its beginning, by its word, and PayPal with a merchant.
foreach ([['Mouser', 'M-1', null], ['AliExpress', 'A-9', null], ['PayPal', 'TX-7', 'Händler GmbH']] as [$quelle, $nummer, $haendler]) {
    $vorschlag = new OrderProposal($quelle, $nummer, ProposalKind::Ordered, '2026-11-19', 'EUR', [new ProposalLine('Teil', 1, '', '')], counterparty: $haendler);
    $erster    = new ModelGateway($ziel('plat'));
    $vorher    = $erster->findOrder($quelle, $nummer);
    $ziel1     = $erster->writingTarget();
    $antwort   = $vorher === null && $ziel1 instanceof TargetModel ? wp_get_ability('taxmod/write-data')->execute(['changes' => (new ChangeListBuilder($ziel1))->newOrder($vorschlag, [0], '')]) : null;
    $zweiter   = (new ModelGateway($ziel('plat')))->findOrder($quelle, $nummer);
    $say($vorher === null && is_array($antwort) && $zweiter !== null && ($zweiter->values['platform'] ?? '') !== '',
        sprintf('zweiter Lauf: «%s» %s wird wiedergefunden (%s), keine Dublette', $quelle, $nummer, is_wp_error($antwort) ? $antwort->get_error_message() : ($zweiter->values['platform'] ?? 'nicht gefunden')));
}

echo $failed === 0 ? "platform-firm-check: ok\n" : "platform-firm-check: {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
