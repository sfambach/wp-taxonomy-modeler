<?php declare(strict_types=1);
/**
 * A module asks the door for the first rule that holds and gets the same answer the core rule book gives — one rule set for
 * printing and testing (D-1541), so no module builds its own.
 *
 *     php scripts/dev/rule-book-check.php [path/to/wordpress]
 *
 * ```mermaid
 * flowchart LR
 *   M["module: its rule records"] --> D["ModelDoor::firstRule"]
 *   D --> B["RuleBook: by position, node + filters"]
 *   B --> A["rule id, or null: the module's default"]
 *   D -->|"«liegt unter»: nodes above, one read"| B
 * ```
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: null;

if ($root === null) {
    $dir = getcwd();
    while ($dir !== '' && ! is_readable($dir . '/wp-load.php')) {
        $up  = dirname($dir);
        $dir = $up === $dir ? '' : $up;
    }
    $root = $dir;
}

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php. Pass the WordPress folder as the first argument.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';

require __DIR__ . '/lib/no-write.php';
wp_set_current_user(1);
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\WordPress\ModelDoor;

$ok  = 0;
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $ok, $bad;

    if ($passed) {
        $ok++;
        echo "  OK   $what\n";

        return;
    }

    $bad++;
    echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n";
}

$door  = new ModelDoor();
$rules = [
    ['id' => 2, 'position' => 1, 'conditions' => [1 => 7], 'filters' => [['field' => 40, 'value' => 'SMD']]],
    ['id' => 3, 'position' => 2, 'conditions' => [1 => 70]],
];

check('the door has firstRule', method_exists($door, 'firstRule'));
check('node and filter hold → that rule', $door->firstRule($rules, [1 => [70, 7]], [40 => ['SMD']]) === 2);
check('filter fails → the next rule', $door->firstRule($rules, [1 => [70, 7]], [40 => ['THT']]) === 3);
check('the caller may refuse a rule', $door->firstRule($rules, [1 => [70, 7]], [40 => ['SMD']], static fn (int $id): bool => $id !== 2) === 3);
check('no rule → null, the caller\'s default', $door->firstRule([$rules[0]], [1 => [5]]) === null);

// D-1563: «liegt unter» — the door reads the nodes above each value from the nodes' own path, in one query.
$kind  = array_key_first($door->children(402));
$below = [['id' => 4, 'position' => 0, 'conditions' => [1 => 70], 'filters' => [['field' => 40, 'value' => '402', 'operator' => Taxmod\Core\Rules\RuleBook::BELOW]]]];
check('a node below the one named holds «liegt unter»', $kind !== null && $door->firstRule($below, [1 => [70]], [40 => [(string) $kind]]) === 4, 'child of Model: ' . var_export($kind, true));
check('the node named itself holds «liegt unter»', $door->firstRule($below, [1 => [70]], [40 => ['402']]) === 4);
check('a node above it does not', $door->firstRule($below, [1 => [70]], [40 => ['1']]) === null);
check('a rule that names no node holds nothing (D-1563)', $door->firstRule([['id' => 5, 'position' => 0, 'conditions' => [1 => 0]]], [1 => [70, 7]]) === null);
check('«liegt unter» has its word', (Taxmod\WordPress\Plugin::operatorWords()[Taxmod\Core\Rules\RuleBook::BELOW] ?? '') !== '');

// D-1569: ▲/▼ in the rule list — the modeller's own sibling buttons, a swap of neighbours, blocked at the ends.
check('two rules swap their positions', Taxmod\Core\Rules\RuleOrder::move([1 => 10, 2 => 9990, 3 => 9991], 3, -1) === [3 => 9990, 2 => 9991]);
$page = Taxmod\WordPress\Admin\RulesPage::fromSetup();
if ($page === null || $page->types() === []) {
    echo "  (no rules page set up here — the list's ▲/▼ is not drawn)\n";
} else {
    $_GET = [];
    $html = $page->render();
    check('the rule list draws ▲/▼ with the sibling icons', str_contains($html, 'value="up"') && str_contains($html, 'dashicons-arrow-up-alt2') && str_contains($html, 'dashicons-arrow-down-alt2'));
}

// Stefan 21.11.: a filter row's operator shows «gleich» when empty, so a new row holding only it is no row.
$setup    = apply_filters(Taxmod\WordPress\Admin\RulesPage::SETUP_FILTER, null);
$operator = is_array($setup) ? absint($setup['filter_fields']['operator'] ?? 0) : 0;
$relation = $operator > 0 ? (new Taxmod\WordPress\Persistence\WpdbRelationRepository())->byId($operator) : null;
if ($relation === null) {
    echo "  (no rule filter set up here — «gleich» alone is not asked)\n";
} else {
    $nodes     = new Taxmod\WordPress\Persistence\WpdbNodeRepository();
    $relations = new Taxmod\WordPress\Persistence\WpdbRelationRepository();
    $framework = new Taxmod\WordPress\Persistence\SeededFrameworkNodes($nodes, $relations, new Taxmod\WordPress\Persistence\WpdbChangelog(new Taxmod\WordPress\SystemClock()));
    $rendering = new Taxmod\Core\Service\Rendering($nodes, $framework, Taxmod\Core\Renderer\ShippedRenderers::registry(), new Taxmod\WordPress\Persistence\SeededTypeNodes($nodes, $framework),
        relations: $relations, resolver: new Taxmod\Core\Service\SettingsResolver(new Taxmod\WordPress\Persistence\WpdbSettingsRepository(), $nodes, Taxmod\Core\Renderer\ShippedRenderers::registry(), Taxmod\Core\Converter\ShippedConverters::registry(), relations: $relations),
        records: new Taxmod\WordPress\Persistence\WpdbRecordRepository());
    check('a filter\'s operator shows «gleich» when empty — alone it makes no new row', $rendering->shownWhenEmpty([$relation]) === [$operator => Taxmod\Core\Rules\RuleBook::EQUALS]);
}

echo "\n$ok OK, $bad FAIL\n";
exit($bad === 0 ? 0 : 1);
