<?php declare(strict_types=1);

/**
 * Ein angezeigter Text führt zur Vorlage seines Anbieters, `{nr}` = der Text ([D-1484](../../docs/NewConcept/90-decision-log.md)).
 *
 * ⚠️ *Seine Worte 2026-11-19: «wäre ja eher so ein feld link + platzhalter und platzhalter ist feldinhalt», «für die einzelnen anbieter
 * ist der link hinterlegt neue können aber angelegt werden also eher eine auswhal».* Geprüft wird:
 *
 * ```text
 * CONTRACT
 * Anbieter aus Feld (Text)    der Name im Feld findet den Anbieter, seine Vorlage gefüllt, URL-kodiert
 * Anbieter aus Feld (Verweis) der verwiesene Anbieter, ohne Namen
 * fester Anbieter             der Name in der Einstellung
 * kein Link                   leerer Inhalt, unbekannter Anbieter, Name nur in einem anderen Text, zwei gleichnamige, Vorlage ohne http(s)
 * Bearbeiten                  bleibt das Eingabefeld
 * ```
 *
 * Usage: php scripts/dev/field-link-check.php
 *
 * @see docs/NewConcept/90-decision-log.md D-1484
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Type\TextType;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Plugin;

wp_set_current_user(1);

$failed = 0;
$say    = static function (bool $ok, string $what) use (&$failed): void {
    printf("  %-4s %s\n", $ok ? 'ok' : 'FAIL', $what);
    $failed += $ok ? 0 : 1;
};

$say(TextType::linkFrom('https://a.test/o?id={nr}', 'A 1/2') === 'https://a.test/o?id=A%201%2F2', 'die Vorlage wird gefüllt, URL-kodiert');
$say(TextType::linkFrom('javascript:alert({nr})', '1') === null && TextType::linkFrom('https://a.test/', '1') === null && TextType::linkFrom('https://a.test/{nr}', ' ') === null, 'nur http(s), mit Platzhalter, mit Inhalt');

$rc     = new ReflectionClass(Plugin::class);
$plugin = $rc->newInstanceWithoutConstructor();
$rc->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$fw   = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin);
$text = (new WpdbNodeRepository())->byImplementations([TextType::class])[TextType::class] ?? null;

if ($text === null) {
    echo "  FAIL kein Texttyp in diesem Modell\n";
    exit(1);
}

$apply = static function (array $changes): array {
    $antwort = wp_get_ability('taxmod/apply')->execute(['changes' => $changes]);

    if (is_wp_error($antwort)) {
        echo '  FAIL Aufbau abgewiesen: ' . $antwort->get_error_message() . "\n";
        exit(1);
    }

    return $antwort['refs'];
};

$model = $fw->rootOf(Branch::Model)->id;
$r     = $apply([
    ['op' => 'create_node', 'ref' => 'firmen', 'name' => '__fl Anbieter', 'parent' => $model],
    ['op' => 'add_field', 'ref' => 'name', 'node' => '@firmen', 'target' => $text->id, 'name' => '__fl Name'],
    ['op' => 'add_field', 'ref' => 'bestell', 'node' => '@firmen', 'target' => $text->id, 'name' => '__fl Bestell-Link'],
    ['op' => 'add_field', 'ref' => 'notiz', 'node' => '@firmen', 'target' => $text->id, 'name' => '__fl Notiz'],
    ['op' => 'create_record', 'ref' => 'ali', 'node' => '@firmen'],
    ['op' => 'set_values', 'record' => '@ali', 'values' => ['@name' => 'AliShop', '@bestell' => 'https://ali.test/order?id={nr}', '@notiz' => 'Nebenwort']],
    ['op' => 'create_record', 'ref' => 'd1', 'node' => '@firmen'],
    ['op' => 'set_values', 'record' => '@d1', 'values' => ['@name' => 'DoppelShop', '@bestell' => 'https://d1.test/{nr}']],
    ['op' => 'create_record', 'ref' => 'd2', 'node' => '@firmen'],
    ['op' => 'set_values', 'record' => '@d2', 'values' => ['@name' => 'DoppelShop', '@bestell' => 'https://d2.test/{nr}']],
    ['op' => 'create_record', 'ref' => 'bad', 'node' => '@firmen'],
    ['op' => 'set_values', 'record' => '@bad', 'values' => ['@name' => 'BadShop', '@bestell' => 'javascript:alert({nr})']],
    ['op' => 'create_node', 'ref' => 'bestellung', 'name' => '__fl Bestellung', 'parent' => $model],
    ['op' => 'add_field', 'ref' => 'plattform', 'node' => '@bestellung', 'target' => $text->id, 'name' => '__fl Plattform'],
    ['op' => 'add_field', 'ref' => 'firma', 'node' => '@bestellung', 'target' => '@firmen', 'name' => '__fl Firma', 'kind' => 'aggregation', 'multiplicity' => '0..1'],
    ['op' => 'add_field', 'ref' => 'nr', 'node' => '@bestellung', 'target' => $text->id, 'name' => '__fl Nummer'],
    ['op' => 'add_field', 'ref' => 'nr2', 'node' => '@bestellung', 'target' => $text->id, 'name' => '__fl Nummer2'],
    ['op' => 'add_field', 'ref' => 'nr3', 'node' => '@bestellung', 'target' => $text->id, 'name' => '__fl Nummer3'],
    ['op' => 'set_list', 'node' => $text->id, 'field' => '@nr', 'attribute' => TextType::LINK_ANBIETER, 'members' => ['@firmen']],
    ['op' => 'set_list', 'node' => $text->id, 'field' => '@nr', 'attribute' => TextType::LINK_VORLAGE, 'members' => ['@bestell']],
    ['op' => 'set_list', 'node' => $text->id, 'field' => '@nr', 'attribute' => TextType::LINK_QUELLE, 'members' => ['@plattform']],
    ['op' => 'set_list', 'node' => $text->id, 'field' => '@nr2', 'attribute' => TextType::LINK_ANBIETER, 'members' => ['@firmen']],
    ['op' => 'set_list', 'node' => $text->id, 'field' => '@nr2', 'attribute' => TextType::LINK_VORLAGE, 'members' => ['@bestell']],
    ['op' => 'set_list', 'node' => $text->id, 'field' => '@nr2', 'attribute' => TextType::LINK_QUELLE, 'members' => ['@firma']],
    ['op' => 'set_list', 'node' => $text->id, 'field' => '@nr3', 'attribute' => TextType::LINK_ANBIETER, 'members' => ['@firmen']],
    ['op' => 'set_list', 'node' => $text->id, 'field' => '@nr3', 'attribute' => TextType::LINK_VORLAGE, 'members' => ['@bestell']],
    ['op' => 'set_setting', 'node' => $text->id, 'field' => '@nr3', 'attribute' => TextType::LINK_FESTER_ANBIETER, 'value' => 'alishop'],
    ['op' => 'create_record', 'ref' => 'b1', 'node' => '@bestellung'],
    ['op' => 'set_values', 'record' => '@b1', 'values' => ['@plattform' => 'AliShop', '@firma' => '@ali', '@nr' => '8 1/2', '@nr2' => '77', '@nr3' => '99']],
    ['op' => 'create_record', 'ref' => 'b2', 'node' => '@bestellung'],
    ['op' => 'set_values', 'record' => '@b2', 'values' => ['@plattform' => 'BadShop', '@nr' => '5']],
    ['op' => 'create_record', 'ref' => 'b3', 'node' => '@bestellung'],
    ['op' => 'set_values', 'record' => '@b3', 'values' => ['@plattform' => 'Unbekannt', '@nr' => '6']],
    ['op' => 'create_record', 'ref' => 'b4', 'node' => '@bestellung'],
    ['op' => 'set_values', 'record' => '@b4', 'values' => ['@plattform' => 'Nebenwort', '@nr' => '7']],
    ['op' => 'create_record', 'ref' => 'b5', 'node' => '@bestellung'],
    ['op' => 'set_values', 'record' => '@b5', 'values' => ['@plattform' => 'DoppelShop', '@nr' => '8']],
]);

$zeige = static function (int $satz) use ($plugin, $r): string {
    $_GET     = ['page' => 'taxmod', 'taxmod_node' => (string) $r['bestellung'], 'taxmod_open_record' => (string) $satz];
    $_REQUEST = $_GET;

    return $plugin->screen()->render();
};

$eins = $zeige($r['b1']);
$say(str_contains($eins, 'class="taxmod-field-link" href="https://ali.test/order?id=8%201%2F2"'), 'Anbieter aus Feld (Text): die Nummer führt zur Bestellung bei AliShop');
$say(str_contains($eins, 'href="https://ali.test/order?id=77"'), 'Anbieter aus Feld (Verweis): die verwiesene Firma, ohne Namen');
$say(str_contains($eins, 'href="https://ali.test/order?id=99"'), 'fester Anbieter: der Name aus der Einstellung, Gross/klein egal');
$say(str_contains($eins, 'name="taxmod_value[' . $r['b1'] . '][' . $r['nr'] . ']"'), 'das Formular bleibt Eingabefeld');
$say(! str_contains($eins, 'id=5"') && ! str_contains($eins, 'href="javascript'), 'eine Vorlage ohne http(s) gibt keinen Link (BadShop, #5)');
$say(! str_contains($eins, 'id=6"'), 'ein unbekannter Anbieter gibt keinen Link (#6)');
$say(! str_contains($eins, 'id=7"'), 'nur das Satzwort zählt, nicht ein anderer Text des Anbieters (#7)');
$say(! str_contains($eins, '.test/8"'), 'zwei Anbieter mit demselben Namen geben keinen Link (#8)');

echo $failed === 0 ? "field-link-check: ok\n" : "field-link-check: {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
