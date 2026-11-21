<?php declare(strict_types=1);

/**
 * The Feldkatalog holds ([D-1553](../../docs/NewConcept/90-decision-log.md)): every target has its role, no module makes a field
 * of its own, and the relations that still point at a base type never grow.
 *
 * ```mermaid
 * flowchart LR
 *   C["code of every wp_tax_* beside the modeller"] --> M["no add_field / addField in src/"]
 *   S["setup: element below Integer, composite with a part"] --> R["fields: target_role per row"]
 *   L["all live fields"] --> B["relations to a base type ≤ ceiling"]
 * ```
 *
 * ⚠️ **It grows with every step of the Bauliste** (konzept.md §0, Prüftor 10:01): relation carries no setting but the summary
 * (step 4), relation points at no base type (step 2, now a ceiling that only sinks), module makes no field (here), one id = one
 * role name (step 5).
 *
 *     php scripts/dev/field-catalog-check.php
 */

define('WP_USE_THEMES', false);


require (getenv('WP_ROOT') ?: 'C:/Devel/Wordpress') . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\CatalogueRole;
use Taxmod\Core\Model\Type\IntType;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;

wp_set_current_user(1);

$failed = 0;

$say = static function (bool $ok, string $what, string $detail = '') use (&$failed): void {
    printf("  %-4s %s%s\n", $ok ? 'ok' : 'FAIL', $what, $ok || $detail === '' ? '' : ' — ' . $detail);

    if (! $ok) {
        ++$failed;
    }
};

echo "== a module makes no field of its own ==\n";

// *Read from the tokens of each module's src/, so a comment or a word in a string is no finding; their checks (scripts/dev)
// build their own test models and are left out.*
$funde = [];

foreach (glob(dirname(__DIR__, 3) . '/wp_tax_*/src') ?: [] as $src) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));

    foreach ($it as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (PhpToken::tokenize((string) file_get_contents($file->getPathname())) as $t) {
            if (($t->is(T_CONSTANT_ENCAPSED_STRING) && trim($t->text, '\'"') === 'add_field') || ($t->is(T_STRING) && $t->text === 'addField')) {
                $funde[] = substr($file->getPathname(), strlen(dirname(__DIR__, 3)) + 1) . ':' . $t->line;
            }
        }
    }
}

$say($funde === [], 'no add_field or addField below any wp_tax_*/src', implode(', ', $funde));

$probe = PhpToken::tokenize("<?php \$x = ['op' => 'add_field'];");
$say(array_filter($probe, static fn (PhpToken $t): bool => $t->is(T_CONSTANT_ENCAPSED_STRING) && trim($t->text, '\'"') === 'add_field') !== [], 'the probe: a call written like a module would write it is seen');

echo "\n== every target has its role ==\n";

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

$fields = wp_get_ability('taxmod/fields');
$rows   = static function (array $input) use ($fields): array {
    $antwort = $fields->execute($input);

    return is_array($antwort) ? array_column(array_map(static fn (array $z): array => array_combine($antwort['columns'], $z), $antwort['rows']), null, 'id') : [];
};

// *Counted before the setup, so the ceiling is the model's own number.*
$vorher = $fields->execute(['target_role' => CatalogueRole::BaseType->value, 'limit' => 1]);
$aufGrundtyp = is_array($vorher) ? (int) $vorher['total'] : -1;

$refs = $apply([
    ['op' => 'create_node', 'ref' => 'jahr', 'name' => '__fc Baujahr', 'parent' => $int->id],
    ['op' => 'create_node', 'ref' => 'mass', 'name' => '__fc Maß', 'parent' => $fw->rootOf(Branch::Model)->id, 'class' => 'CompositeType'],
    ['op' => 'create_node', 'ref' => 'pos', 'name' => '__fc Position', 'parent' => $fw->rootOf(Branch::Model)->id],
    ['op' => 'add_field', 'ref' => 'teil', 'node' => '@mass', 'target' => '@jahr', 'name' => '__fc Länge'],
    ['op' => 'create_node', 'ref' => 'ding', 'name' => '__fc Ding', 'parent' => $fw->rootOf(Branch::Model)->id],
    ['op' => 'add_field', 'ref' => 'f1', 'node' => '@ding', 'target' => '@mass', 'name' => '__fc Abmessung', 'kind' => 'composition'],
    ['op' => 'add_field', 'ref' => 'f2', 'node' => '@ding', 'target' => '@jahr', 'name' => '__fc Jahr'],
    ['op' => 'add_field', 'ref' => 'f3', 'node' => '@ding', 'target' => $int->id, 'name' => '__fc Zahl'],
    ['op' => 'add_field', 'ref' => 'pteil', 'node' => '@pos', 'target' => '@jahr', 'name' => '__fc Menge'],
    ['op' => 'add_field', 'ref' => 'f4', 'node' => '@ding', 'target' => '@pos', 'name' => '__fc Positionen', 'kind' => 'composition'],
]);

$zeilen = $rows(['node' => (int) $refs['ding']]);

$say(($zeilen[(int) $refs['f1']]['target_role'] ?? '') === 'composite', 'a node of the class «composite data type» is a composite', wp_json_encode($zeilen[(int) $refs['f1']] ?? null));
$say(($zeilen[(int) $refs['f4']]['target_role'] ?? '') === 'outside', 'a node merely held by composition (positions) is not one', wp_json_encode($zeilen[(int) $refs['f4']] ?? null));
$say(($zeilen[(int) $refs['f2']]['target_role'] ?? '') === 'data_element', 'a node below Integer is a data element', wp_json_encode($zeilen[(int) $refs['f2']] ?? null));
$say(($zeilen[(int) $refs['f3']]['target_role'] ?? '') === 'base_type', 'Integer itself is a base type', wp_json_encode($zeilen[(int) $refs['f3']] ?? null));

$nurGrund = $rows(['node' => (int) $refs['ding'], 'target_role' => 'base_type']);
$say(array_keys($nurGrund) === [(int) $refs['f3']], 'the target_role filter keeps only the field on the base type', wp_json_encode(array_keys($nurGrund)));

echo "\n== relations to a base type never grow ==\n";

// ⚠️ *The ceiling is the number of a fresh install (the seeded scaffold) and only ever lowered. Run against a copy of the live
// model, the number is printed for the measurement and the ceiling of the copy is passed in FIELD_CATALOG_CEILING.*
$decke = getenv('FIELD_CATALOG_CEILING') !== false ? (int) getenv('FIELD_CATALOG_CEILING') : (int) json_decode((string) file_get_contents(__DIR__ . '/lib/field-catalog-ceiling.json'), true)['base_type_relations'];

printf("  info relations to a base type: %d (ceiling %d)\n", $aufGrundtyp, $decke);
$say($aufGrundtyp >= 0 && $aufGrundtyp <= $decke, 'not above the ceiling', $aufGrundtyp . ' > ' . $decke);

echo $failed === 0 ? "\nfield-catalog-check: all good\n" : "\nfield-catalog-check: {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
