<?php declare(strict_types=1);

/**
 * `settings.path` — the address, against a real database.
 *
 * ⚠️ **The column was built before its consumers on purpose**, so this check is what stands in for
 * them: it proves the address exists, that two answers under one key can coexist, and — the part that
 * matters most — that **a path never falls back to the empty one**.
 *
 * ⚠️ *That last one is not a nicety. The prefix exponent was measured on 2026-08-26 to be written and
 * not functioning because `kilo`'s `default = 3` sat at the empty path while the `exponent` attribute
 * looked somewhere else. **A silent fallback would have hidden that fault instead of ending it.***
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
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Renderer\ShippedRenderers;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\Rendering;
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\SeededTypeNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\Persistence\WpdbSettingRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;

$ok  = 0;
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $ok, $bad;

    if ($passed) {
        $ok++;
        echo "  OK   $what\n";
    } else {
        $bad++;
        echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

Schema::install();
update_option(Schema::VERSION_OPTION, Schema::VERSION, true);

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($nodes, $edges, new TableIdentityAllocator(), new WpdbChangelog(new SystemClock()));
$framework->seed();

$editor   = new ModelEditor($nodes, $edges, new TableIdentityAllocator(), $framework, new WpdbChangelog(new SystemClock()));
$settings = new Settings(new WpdbSettingRepository(), $nodes, $framework);

echo "\n== 1. The column and the key ==\n";

$table = Schema::table('settings');

$columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");

check('`path` is a column', in_array('path', $columns, true), implode(', ', $columns));

// ⚠️ **The index is the half a schema bump does not do**: `dbDelta` never touches one it already
// created, so this is what catches a migration that added the column and left the key alone.
$keyed = $wpdb->get_col($wpdb->prepare(
    'SELECT COLUMN_NAME FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
     ORDER BY SEQ_IN_INDEX',
    $table,
    'owner_key'
));

check(
    'the unique key is (owner_id, setting_key, path)',
    $keyed === ['owner_id', 'setting_key', 'path'],
    implode(', ', $keyed)
);

echo "\n== 2. One key, two places ==\n";

$thing = $editor->createNode('__path Thing', $framework->rootOf(Branch::Model)->id);
$text  = $editor->createNode('__path Text', $framework->rootOf(Branch::DataTypes)->id);
$one   = $editor->addField($thing->id, $text->id, '__path first');
$two   = $editor->addField($thing->id, $text->id, '__path second');

$chain = $settings->chainFor($thing);

$settings->put($chain, SettingKey::DefaultValue->value, TypedValue::ofText('for the node itself'));
$settings->put($chain, SettingKey::DefaultValue->value, TypedValue::ofText('for the first'), (string) $one->id);
$settings->put($chain, SettingKey::DefaultValue->value, TypedValue::ofText('for the second'), (string) $two->id);

$rows = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE owner_id = %d AND setting_key = %s",
    $thing->id,
    SettingKey::DefaultValue->value
));

check('three rows for one key at one owner', $rows === 3, (string) $rows);

$atNode   = $settings->resolve($chain)[SettingKey::DefaultValue->value] ?? null;
$atFirst  = $settings->resolve($chain, (string) $one->id)[SettingKey::DefaultValue->value] ?? null;
$atSecond = $settings->resolve($chain, (string) $two->id)[SettingKey::DefaultValue->value] ?? null;

check('the node reads its own', $atNode?->value->text === 'for the node itself', $atNode?->value->text ?? 'nothing');
check('the first attribute reads its own', $atFirst?->value->text === 'for the first', $atFirst?->value->text ?? 'nothing');
check('the second attribute reads its own', $atSecond?->value->text === 'for the second', $atSecond?->value->text ?? 'nothing');

echo "\n== 3. A path does not fall back to the empty one ==\n";

$third = $editor->addField($thing->id, $text->id, '__path third');

// Nothing was ever written at this path, and the node's own default must not stand in for it.
$atThird = $settings->resolve($chain, (string) $third->id)[SettingKey::DefaultValue->value] ?? null;

check(
    'an unwritten path answers nothing, not the owner\'s value',
    $atThird === null,
    $atThird === null ? '' : 'it answered «' . $atThird->value->text . '»'
);

echo "\n== 4. Forgetting one place leaves the others ==\n";

$settings->reset($thing->id, SettingKey::DefaultValue->value, (string) $one->id);

check(
    'the first is gone',
    ($settings->resolve($chain, (string) $one->id)[SettingKey::DefaultValue->value] ?? null) === null
);

check(
    'the second is untouched',
    ($settings->resolve($chain, (string) $two->id)[SettingKey::DefaultValue->value] ?? null)?->value->text === 'for the second'
);

check(
    'and so is the node\'s own',
    ($settings->resolve($chain)[SettingKey::DefaultValue->value] ?? null)?->value->text === 'for the node itself'
);

echo "\n== 5. The first real consumer: a prefix reads its exponent ==\n";

// ⚠️ **This is what turns the column from a claim into a mechanism.** [D-378] made the exponent an
// **attribute** of `Prefixes`, so that only prefixes have one, and its value lives as a `default`
// ([D-026]). It had been written at the **empty** path — *kilo's own default* — where the attribute
// could never see it, because a use site resolves from its **target's** chain and `kilo` is not in
// it. **Written and not functioning for four days**, measured 2026-08-26.
$rendering = new Rendering(
    $nodes,
    $framework,
    $settings,
    ShippedRenderers::registry(),
    new SeededTypeNodes($nodes, $framework),
    new Labels(new WpdbLabelRepository(), $framework)
);

$nodeNamed = static function (string $name) use ($wpdb, $nodes) {
    $id = (int) $wpdb->get_var($wpdb->prepare(
        'SELECT id FROM ' . Schema::table('nodes') . ' WHERE name = %s LIMIT 1',
        $name
    ));

    return $id === 0 ? null : $nodes->find($id);
};

$prefixes = $nodeNamed('Prefixes');
$exponent = null;

// ⚠️ **Not `$one`.** That variable holds the first scratch attribute, and reusing it here made the
// tidying at the end try to remove the **exponent** edge from the scratch node. *The core refused it
// — an inherited attribute is changed where it is declared — so a guard caught what a careless
// variable name had started.*
foreach ($prefixes === null ? [] : $editor->fieldsOf($prefixes->id) as $candidate) {
    if ($candidate->name === 'exponent') {
        $exponent = $candidate;
    }
}

if ($exponent === null) {
    echo "  --   no exponent attribute; the unit scaffold has not run here\n";
} else {
    foreach (['kilo' => 3, 'mega' => 6, 'milli' => -3, 'yotta' => 24] as $name => $power) {
        $node = $nodeNamed($name);
        $read = $node === null ? null : $rendering->nonPersistentValue($node, $exponent);

        check(
            "«{$name}» reads its exponent through the attribute",
            $read?->int === $power,
            $read === null ? 'nothing' : $read->describe()
        );
    }

    // ⚠️ *And a node that is not a prefix says **nothing** rather than zero — a missing row is a
    // different fact from a value of none, which is the whole reason a path does not fall back.*
    $ohm = $nodeNamed('Ohm');

    if ($ohm !== null) {
        check('a unit is not a prefix and answers nothing', $rendering->nonPersistentValue($ohm, $exponent) === null);
    }
}

echo "\n== 6. Tidying up ==\n";

$wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE owner_id = %d", $thing->id));

foreach ([$one, $two, $third] as $edge) {
    $editor->removeField($thing->id, $edge->id);
}

$editor->moveToTrash($thing->id);
$editor->moveToTrash($text->id);

check(
    'the scratch settings are gone',
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE owner_id = %d", $thing->id)) === 0
);

echo "\n---- {$ok} passed, {$bad} failed ----\n";

exit($bad === 0 ? 0 : 1);
