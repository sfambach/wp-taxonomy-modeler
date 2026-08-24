<?php declare(strict_types=1);
/**
 * Package 4 acceptance check — settings and the chain, against a real database.
 *
 *     php scripts/dev/package4-check.php [path/to/wordpress]
 *
 * The owner's test: a default at the type, an override at the attribute, reset to inherited.
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

use Taxmod\Core\Exception\CannotWiden;
use Taxmod\Core\Exception\ReservedKey;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
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
    if ($passed) { $ok++; echo "  OK   $what\n"; }
    else { $bad++; echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

Schema::install();
update_option(Schema::VERSION_OPTION, Schema::VERSION, true);

$nodes     = new WpdbNodeRepository();
$edges     = new WpdbRelationRepository();
$ids       = new TableIdentityAllocator();
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, $ids, $log);
$framework->seed();

$editor   = new ModelEditor($nodes, $edges, $ids, $framework, $log);
$stored   = new WpdbSettingRepository();
$settings = new Settings($stored, $nodes, $framework);

$installation = $framework->installationId();

echo "\n== 1. The installation is an identity, not a node ==\n";
check('it has an identity row', (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('identities') . ' WHERE id = %d', $installation)) === 1);
check('and no node behind it', $nodes->find($installation) === null);
check('so a foreign key can still point at it', $installation > 0);

echo "\n== 2. The chain ==\n";
$thing = $editor->createNode('__p4 Thing', $framework->rootOf(Branch::Model)->id);
$part  = $editor->createNode('__p4 Part', $thing->id);
$text  = $editor->createNode('__p4 Text', $framework->rootOf(Branch::DataTypes)->id);
$edge  = $editor->addAttribute($part->id, $text->id, '__p4 description');

$chainType = $settings->chainFor($text);
$chainSite = $settings->chainForUseSite($edge);

check('it starts at the installation', $chainType[0] === $installation);
check('it follows the path', $chainType === [$installation, ...$text->ancestorIds(), $text->id], implode('·', $chainType));
check('the use site is the last link', end($chainSite) === $edge->id);

echo "\n== 3. A default at the type, an override at the attribute ==\n";
$settings->put($chainType, SettingKey::Renderer->value, TypedValue::ofText('__p4 plain'));
check('the type carries it', $settings->resolve($chainType)[SettingKey::Renderer->value]->value->text === '__p4 plain');
check('and the use site inherits it', $settings->resolve($chainSite)[SettingKey::Renderer->value]->isInherited());

$settings->put($chainSite, SettingKey::Renderer->value, TypedValue::ofText('__p4 markdown'));
check('the override wins at the use site', $settings->resolve($chainSite)[SettingKey::Renderer->value]->value->text === '__p4 markdown');
check('and the type is untouched', $settings->resolve($chainType)[SettingKey::Renderer->value]->value->text === '__p4 plain');

echo "\n== 4. Reset to inherited ==\n";
$settings->reset($edge->id, SettingKey::Renderer->value);
check('the row is gone', (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('settings') . ' WHERE owner_id = %d AND setting_key = %s',
    $edge->id, SettingKey::Renderer->value)) === 0);
check('and it inherits again', $settings->resolve($chainSite)[SettingKey::Renderer->value]->value->text === '__p4 plain');

echo "\n== 5. Deliberately nothing is not the same thing ==\n";
$settings->put($chainSite, SettingKey::Renderer->value, TypedValue::nothing());
check('the row exists', (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('settings') . ' WHERE owner_id = %d AND setting_key = %s',
    $edge->id, SettingKey::Renderer->value)) === 1);
check('and it holds nothing', $settings->resolve($chainSite)[SettingKey::Renderer->value]->value->isNothing());

$settings->put($chainType, SettingKey::Renderer->value, TypedValue::ofText('__p4 changed later'));
check('a later change above does not reach it', $settings->resolve($chainSite)[SettingKey::Renderer->value]->value->isNothing());
$settings->reset($edge->id, SettingKey::Renderer->value);
check('but after a reset it does', $settings->resolve($chainSite)[SettingKey::Renderer->value]->value->text === '__p4 changed later');

echo "\n== 6. Storage is sparse ==\n";
$rows = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('settings') . ' WHERE owner_id IN (%d, %d, %d)',
    $installation, $text->id, $edge->id));
check('one written setting, one row', $rows === 1, "$rows rows");

echo "\n== 7. Bounding narrows, choosing is free ==\n";
$settings->put($chainType, SettingKey::MultiplicityMax->value, TypedValue::ofInt(5));
$settings->put($chainSite, SettingKey::MultiplicityMax->value, TypedValue::ofInt(2));
check('a maximum may be lowered', $settings->resolve($chainSite)[SettingKey::MultiplicityMax->value]->value->int === 2);

try { $settings->put($chainSite, SettingKey::MultiplicityMax->value, TypedValue::ofInt(9)); check('and may not be raised', false); }
catch (CannotWiden $e) { check('and may not be raised', true); }

$settings->put($chainType, SettingKey::Mandatory->value, TypedValue::ofBool(true));
try { $settings->put($chainSite, SettingKey::Mandatory->value, TypedValue::ofBool(false)); check('mandatory stays mandatory', false); }
catch (CannotWiden $e) { check('mandatory stays mandatory', true); }

$settings->put($chainType, SettingKey::DefaultValue->value, TypedValue::ofText('a'));
$settings->put($chainSite, SettingKey::DefaultValue->value, TypedValue::ofText('b'));
check('a default is free', $settings->resolve($chainSite)[SettingKey::DefaultValue->value]->value->text === 'b');

echo "\n== 8. Reserved names ==\n";
try { $settings->declareFree($chainType, 'hide', TypedValue::ofBool(true)); check('an engine name is refused', false); }
catch (ReservedKey $e) { check('an engine name is refused', true); }
$settings->declareFree($chainType, '__p4 mine', TypedValue::ofText('yes'));
check('a name of its own is allowed', $settings->resolve($chainType)['__p4 mine']->value->text === 'yes');

echo "\n== 9. Typed columns, no stringly value ==\n";
$settings->put($chainType, SettingKey::RangeMax->value, TypedValue::ofDecimal('2.50'));
$row = $wpdb->get_row($wpdb->prepare(
    'SELECT value_int, value_decimal, value_text FROM ' . Schema::table('settings') . '
     WHERE owner_id = %d AND setting_key = %s', $text->id, SettingKey::RangeMax->value), ARRAY_A);
check('a decimal lands in value_decimal', $row['value_decimal'] !== null && $row['value_text'] === null, json_encode($row));
$back = $settings->resolve($chainType)[SettingKey::RangeMax->value]->value->decimal;
check('and comes back exact, though not in the notation it was typed in', $back !== null && bccomp($back, '2.50', 10) === 0, var_export($back, true));

echo "\n== 10. The check cleans up after itself ==\n";
foreach ([$thing->id, $text->id] as $scratch) {
    $node = $nodes->find($scratch);
    if ($node !== null) { $edges->purgeEdgesTouching($node->id); $nodes->purgeSubtree($node); }
}
$wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('settings') . ' WHERE owner_id IN (%d, %d, %d) OR setting_key LIKE %s',
    $text->id, $edge->id, $part->id, '__p4%'));
$wpdb->query('DELETE FROM ' . Schema::table('relations') . ' WHERE name LIKE "__p4%"');
$wpdb->query('DELETE FROM ' . Schema::table('changelog') . ' WHERE after_state LIKE "%__p4%"');
$left = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('nodes') . ' WHERE name LIKE "__p4%"');
check('scratch nodes are gone', $left === 0, "$left left");
$orphanSettings = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('settings') . ' s
     LEFT JOIN ' . Schema::table('identities') . ' i ON i.id = s.owner_id
     WHERE i.id IS NULL'
);
check('no setting hangs on an identity that never existed', $orphanSettings === 0, "$orphanSettings orphans");

echo "\n---- $ok passed, $bad failed ----\n";
exit($bad === 0 ? 0 : 1);
