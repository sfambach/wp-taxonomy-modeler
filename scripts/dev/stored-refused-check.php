<?php declare(strict_types=1);

/**
 * Which stored values their validators would refuse when written again — since D-1549 also as a row of a list or a field of a
 * part, which went past every validator before. A red line names a write path that used to save and now is refused.
 *
 * ```mermaid
 * flowchart LR
 *   F["every field with range · shape · syntax"] --> V["its stored values, one read"]
 *   V --> C["complaintsFor each value"]
 *   C --> R["per field: where it stands (plain · list · part), how many"]
 * ```
 *
 * ⚠️ *A measurement of the data in this database, not of the code: against a copy of the live model it answers «which module
 * writes would now be refused»; on a fresh install it has nothing to find.*
 *
 *     php scripts/dev/stored-refused-check.php [--list]
 */

define('WP_USE_THEMES', false);

require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\RelationKind;
use Taxmod\WordPress\Persistence\WpdbRecordRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;

wp_set_current_user(1);

$r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');

$relations = (new WpdbRelationRepository())->everyFieldRelation();
$byId      = [];
$parts     = [];

foreach ($relations as $one) {
    $byId[$one->id] = $one;

    if ($one->kind === RelationKind::Composition) {
        $parts[$one->toNodeId] = true;
    }
}

$records = new WpdbRecordRepository();
$refused = $plugin->screen()->storedValuesRefused(
    array_values(array_filter($relations, static fn ($one): bool => $one->kind !== RelationKind::Setting)),
    static fn (array $ids): array => $records->valuesOnFields($ids)
);

$perField = [];

foreach ($refused as $row) {
    $field = $byId[$row['field']];
    $where = isset($parts[$field->fromNodeId]) ? 'part' : ($field->multiplicity->allowsMany() ? 'list' : 'plain');
    $key   = $row['field'];

    $perField[$key] ??= ['field' => $key, 'name' => $field->name, 'node' => $field->fromNodeId, 'where' => $where, 'count' => 0, 'first' => $row];
    ++$perField[$key]['count'];
}

$newlyRefused = array_filter($perField, static fn (array $f): bool => $f['where'] !== 'plain');

foreach ($perField as $f) {
    printf("  %-4s field %d «%s» at node %d (%s): %d stored value(s) refused, e.g. record %d «%s» — %s\n", $f['where'] === 'plain' ? 'info' : 'FAIL', $f['field'], $f['name'], $f['node'], $f['where'], $f['count'], $f['first']['record'], $f['first']['value'], $f['first']['complaint']);

    if (in_array('--list', $argv, true)) {
        foreach (array_filter($refused, static fn (array $one): bool => $one['field'] === $f['field']) as $one) {
            printf("         record %d «%s» — %s\n", $one['record'], $one['value'], $one['complaint']);
        }
    }
}

echo $newlyRefused === []
    ? "\nAll good: no stored list row or part value is refused by its validator (" . count($perField) . " plain field(s) as info).\n"
    : "\n" . count($newlyRefused) . " field(s) whose list rows or part values would now be refused.\n";

exit($newlyRefused === [] ? 0 : 1);
