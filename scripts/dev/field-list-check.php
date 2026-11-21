<?php declare(strict_types=1);

/**
 * The tools of the Feldkatalog migration answer from the live model in one call each, and write-data refuses what a validator
 * refuses wherever the value stands (konzept.md §8 M-b, M-c, M-e).
 *
 * ```mermaid
 * flowchart LR
 *   S["setup: node, three integer fields, range min 3"] --> F["fields: rows, counts, own settings, filters"]
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
    ['op' => 'add_field', 'ref' => 'c', 'node' => '@n', 'target' => $int->id, 'name' => '__fl Leer'],
    ['op' => 'create_record', 'ref' => 's', 'node' => '@n', 'values' => ['@a' => '7']],
    // *Stored before the validator stands — what stored-refused-check is to find.*
    ['op' => 'create_record', 'ref' => 'alt', 'node' => '@n', 'values' => ['@b' => ['values' => ['2']]]],
]);

foreach (['a', 'b'] as $feld) {
    $apply([
        ['op' => 'set_addons', 'node' => $int->id, 'field' => $refs[$feld], 'addons' => [['name' => 'range']]],
        ['op' => 'set_setting', 'node' => $int->id, 'field' => $refs[$feld], 'attribute' => 'min', 'value' => '3'],
    ]);
}

echo "== taxmod/fields ==\n";

$liste = $fields->execute(['node' => (int) $refs['n']]);
$say(is_array($liste) && $liste['total'] === 3, 'the node filter finds the node\'s three fields', is_wp_error($liste) ? $liste->get_error_message() : wp_json_encode($liste));

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
$say(is_array($mitWerten) && $mitWerten['total'] === 2, 'with_values leaves out the empty field');

echo "\n== taxmod/similar-fields ==\n";

$gleich = $similar->execute(['name' => '__fl Druckaufloesung', 'type' => 'int', 'settings' => ['min' => '3']]);
$say(is_array($gleich) && ($gleich['matches'][0]['relation'] ?? 0) === (int) $refs['a'], 'the same field comes first', is_wp_error($gleich) ? $gleich->get_error_message() : wp_json_encode($gleich['matches'][0] ?? null));
$say(is_array($gleich) && ($gleich['matches'][0]['matching_settings'] ?? []) === ['min'], 'it names the matching setting');

$anderer = $similar->execute(['name' => '__fl Druckauflösung', 'type' => 'text']);
$say(is_array($anderer) && ! in_array((int) $refs['a'], array_column($anderer['matches'], 'relation'), true), 'another type is not similar');

// D-1554 (concept change, PR-9): «a form error is refused, a range error is saved and reported» — before, a range error refused.
echo "\n== write-data saves a range error and reports it ==\n";

$vorher = count((new WpdbRecordRepository())->valuesOf((int) $refs['s']));
$antwort = wp_get_ability('taxmod/write-data')->execute(['changes' => [
    ['op' => 'set_values', 'record' => (int) $refs['s'], 'values' => [(string) $refs['b'] => ['values' => ['5', '1']]]],
]]);

$say(is_array($antwort), 'a row of a list below min does not refuse the list', is_wp_error($antwort) ? $antwort->get_error_code() . ' ' . $antwort->get_error_message() : '');

$gemeldet = is_array($antwort) ? ($antwort['reported'] ?? []) : [];
$say(count($gemeldet) === 1 && ($gemeldet[0]['key'] ?? '') === 'below_min' && ($gemeldet[0]['field'] ?? 0) === (int) $refs['b'] && ($gemeldet[0]['record'] ?? 0) === (int) $refs['s'] && ($gemeldet[0]['text'] ?? '') !== '', 'the answer reports validator key, field, record and words', wp_json_encode($gemeldet));
$say(count((new WpdbRecordRepository())->valuesOf((int) $refs['s'])) === $vorher + 2, 'both rows were written');

$einfach = wp_get_ability('taxmod/write-data')->execute(['changes' => [
    ['op' => 'set_values', 'record' => (int) $refs['s'], 'values' => [(string) $refs['a'] => '2']],
]]);
$say(is_array($einfach) && count($einfach['reported'] ?? []) === 1, 'a plain value below min is saved and reported the same way');

$passend = wp_get_ability('taxmod/write-data')->execute(['changes' => [
    ['op' => 'set_values', 'record' => (int) $refs['s'], 'values' => [(string) $refs['a'] => '7']],
]]);
$say(is_array($passend) && ! isset($passend['reported']), 'a fitting value reports nothing');

echo "\n== stored values a validator would now refuse ==\n";

$records  = new WpdbRecordRepository();
$verweigert = $plugin->screen()->storedValuesRefused(
    (new \Taxmod\WordPress\Persistence\WpdbRelationRepository())->everyFieldRelation(),
    static fn (array $ids): array => $records->valuesOnFields($ids)
);
$alt = array_values(array_filter($verweigert, static fn (array $z): bool => $z['record'] === (int) $refs['alt']));

$say(count($alt) === 1 && $alt[0]['field'] === (int) $refs['b'] && $alt[0]['value'] === '2', 'the list row stored before the validator is named', wp_json_encode($alt));
$say(count(array_filter($verweigert, static fn (array $z): bool => $z['record'] === (int) $refs['s'])) === 1, 'the row saved with a note is named, the fitting value is not');

echo $failed === 0 ? "\nAll good.\n" : "\n$failed FAILED\n";

exit($failed === 0 ? 0 : 1);
