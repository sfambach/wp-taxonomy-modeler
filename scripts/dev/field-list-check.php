<?php declare(strict_types=1);

/**
 * The tools of the Feldkatalog migration answer from the live model in one call each, and write-data refuses what a validator
 * refuses wherever the value stands (konzept.md §8 M-b, M-c, M-e).
 *
 * ```mermaid
 * flowchart LR
 *   S["setup: node, two integer fields, range min 3"] --> F["fields: rows, counts, own settings, filters"]
 *   S --> A["similar-fields: the same field first"]
 *   S --> W["write-data: a row of a list below min"]
 *   W --> X["taxmod_invalid, complaint with its place, nothing written"]
 * ```
 *
 *     php scripts/dev/field-list-check.php
 */

define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Type\IntType;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;

wp_set_current_user(1);

$failed = 0;

$say = static function (bool $ok, string $what, string $detail = '') use (&$failed): void {
    printf("  %-4s %s%s\n", $ok ? 'ok' : 'FAIL', $what, $ok || $detail === '' ? '' : ' — ' . $detail);

    if (! $ok) {
        ++$failed;
    }
};

$fields  = wp_get_ability('taxmod/fields');
$similar = wp_get_ability('taxmod/similar-fields');

$say($fields !== null && $similar !== null, 'fields and similar-fields are registered');

if ($fields === null || $similar === null) {
    exit(1);
}

$say(($fields->get_meta_item('annotations')['readonly'] ?? null) === true && ($similar->get_meta_item('annotations')['readonly'] ?? null) === true, 'both only read');

$r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');

$fw  = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin);
$int = (new WpdbNodeRepository())->byImplementations([IntType::class])[IntType::class] ?? null;

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
    ['op' => 'create_node', 'ref' => 'n', 'name' => '__fl Bauteil', 'parent' => $fw->rootOf(Branch::Model)->id],
    ['op' => 'add_field', 'ref' => 'a', 'node' => '@n', 'target' => $int->id, 'name' => '__fl Druckauflösung'],
    ['op' => 'add_field', 'ref' => 'b', 'node' => '@n', 'target' => $int->id, 'name' => '__fl Gewichte', 'multiplicity' => '0..*'],
    ['op' => 'create_record', 'ref' => 's', 'node' => '@n', 'values' => ['@a' => '7']],
]);

foreach (['a', 'b'] as $feld) {
    $apply([
        ['op' => 'set_addons', 'node' => $int->id, 'field' => $refs[$feld], 'addons' => [['name' => 'range']]],
        ['op' => 'set_setting', 'node' => $int->id, 'field' => $refs[$feld], 'attribute' => 'min', 'value' => '3'],
    ]);
}

echo "== taxmod/fields ==\n";

$liste = $fields->execute(['node' => (int) $refs['n']]);
$say(is_array($liste) && $liste['total'] === 2, 'the node filter finds the node\'s two fields', is_wp_error($liste) ? $liste->get_error_message() : wp_json_encode($liste));

$zeilen = is_array($liste) ? array_map(static fn (array $z): array => array_combine($liste['columns'], $z), $liste['rows']) : [];
$a      = array_column($zeilen, null, 'id')[(int) $refs['a']] ?? [];

$say(($a['type'] ?? '') === 'int' && ($a['target'] ?? 0) === $int->id && ($a['node_name'] ?? '') === '__fl Bauteil', 'a row names type, target and owner', wp_json_encode($a));
$say(($a['values'] ?? 0) === 1 && ($a['records'] ?? 0) === 1, 'a row counts its values and records', wp_json_encode($a));
$say((((array) ($a['settings'] ?? []))['min'] ?? null) === '3', 'a row carries the settings stored at the field', wp_json_encode($a['settings'] ?? null));

$alle = $fields->execute(['limit' => 1]);
$say(is_array($alle) && count($alle['rows']) === 1 && $alle['total'] > 2, 'one page of all fields, with the total', is_array($alle) ? (string) $alle['total'] : '');

$nachName = $fields->execute(['name' => 'fl DRUCKAUFLOESUNG']);
$say(is_array($nachName) && array_column(array_map(static fn (array $z): array => array_combine($nachName['columns'], $z), $nachName['rows']), 'id') === [(int) $refs['a']], 'the name filter folds case and umlauts');

$mitWerten = $fields->execute(['node' => (int) $refs['n'], 'with_values' => true]);
$say(is_array($mitWerten) && $mitWerten['total'] === 1, 'with_values leaves out the empty field');

echo "\n== taxmod/similar-fields ==\n";

$gleich = $similar->execute(['name' => '__fl Druckaufloesung', 'type' => 'int', 'settings' => ['min' => '3']]);
$say(is_array($gleich) && ($gleich['matches'][0]['relation'] ?? 0) === (int) $refs['a'], 'the same field comes first', is_wp_error($gleich) ? $gleich->get_error_message() : wp_json_encode($gleich['matches'][0] ?? null));
$say(is_array($gleich) && ($gleich['matches'][0]['matching_settings'] ?? []) === ['min'], 'it names the matching setting');

$anderer = $similar->execute(['name' => '__fl Druckauflösung', 'type' => 'text']);
$say(is_array($anderer) && ! in_array((int) $refs['a'], array_column($anderer['matches'], 'relation'), true), 'another type is not similar');

echo "\n== write-data reports what a validator refuses ==\n";

$vorher = count((new WpdbRecordRepository())->valuesOf((int) $refs['s']));
$antwort = wp_get_ability('taxmod/write-data')->execute(['changes' => [
    ['op' => 'set_values', 'record' => (int) $refs['s'], 'values' => [(string) $refs['b'] => ['values' => ['5', '1']]]],
]]);

$say(is_wp_error($antwort) && $antwort->get_error_code() === 'taxmod_invalid', 'a row of a list below min refuses the list', is_wp_error($antwort) ? $antwort->get_error_code() . ' ' . $antwort->get_error_message() : 'written');

$beschwerden = is_wp_error($antwort) ? ($antwort->get_error_data()['complaints'] ?? []) : [];
$say(count($beschwerden) === 1 && ($beschwerden[0]['key'] ?? '') === 'below_min' && ($beschwerden[0]['field'] ?? 0) === (int) $refs['b'] && ($beschwerden[0]['record'] ?? 0) === (int) $refs['s'], 'the complaint names validator key, field and record', wp_json_encode($beschwerden));
$say(count((new WpdbRecordRepository())->valuesOf((int) $refs['s'])) === $vorher, 'nothing was written');

$einfach = wp_get_ability('taxmod/write-data')->execute(['changes' => [
    ['op' => 'set_values', 'record' => (int) $refs['s'], 'values' => [(string) $refs['a'] => '2']],
]]);
$say(is_wp_error($einfach) && $einfach->get_error_code() === 'taxmod_invalid', 'a plain value below min is refused the same way');

echo $failed === 0 ? "\nAll good.\n" : "\n$failed FAILED\n";

exit($failed === 0 ? 0 : 1);
