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
use Taxmod\Core\Exception\SettingDoesNotApply;
use Taxmod\Core\Model\Branch;
use Taxmod\Core\Model\Multiplicity;
use Taxmod\Core\Model\SettingKey;
use Taxmod\Core\Model\TypedValue;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\Core\Service\Settings;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
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
$log       = new WpdbChangelog(new SystemClock());
$framework = new SeededFrameworkNodes($nodes, $edges, $log);
$framework->seed();

$editor   = new ModelEditor($nodes, $edges, $framework, $log);
$stored   = new WpdbSettingRepository();
// ⚠️ *Der Kantenspeicher fährt seit TASK-004 mit: ohne ihn hält {@see Settings} eine Kante für einen
// Knoten, sobald beide dieselbe Nummer tragen — was mit eigenen Id-Räumen der Normalfall ist.*
$settings = new Settings($stored, $nodes, $framework, null, $edges);

$installation = $framework->installationId();

// ⚠️ **Die Zusage hat sich mit TASK-004 geändert** (`PR-9`): *bis Fassung 20 verlangte sie eine Zeile
// in `identities`. Die Tabelle ist gestrichen; was bleibt und was zählt, ist, dass die
// Installationsidentität **weder Knoten noch Kante** ist — sonst läse die Einstellungskette die
// Einstellungen eines fremden Dings als Vorgabe der Installation. **Wo sie künftig wohnt, ist
// `INF-008`** in [`inbox.md`](../../docs/pakete/modelltabellen/inbox.md).*
echo "\n== 1. The installation is an identity, not a node ==\n";
check('no node behind it', $nodes->find($installation) === null);
check('und auch keine Kante', (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('relations') . ' WHERE id = %d', $installation)) === 0);
check('sie ist eine Nummer', $installation > 0);

echo "\n== 2. The chain ==\n";
$thing = $editor->createNode('__p4 Thing', $framework->rootOf(Branch::Model)->id);
$part  = $editor->createNode('__p4 Part', $thing->id);
$text  = $editor->createNode('__p4 Text', $framework->rootOf(Branch::DataTypes)->id);
$edge  = $editor->addField($part->id, $text->id, '__p4 description');

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
// ⚠️ **Counted per key, not per owner, and that is a correction rather than a loosening.** This line
// counted every row belonging to the installation, the type and the edge — which was the same number
// as «rows for the key I wrote» only for as long as the installation held **nothing**. Since
// [D-404](../../docs/NewConcept/90-decision-log.md) it holds a key's own default (`persistent = true`),
// so the count went to 2 and the check reported the opposite of what it meant.
//
// ⚠️ *Sparseness is a claim about **one key**: writing `renderer` in one place must leave one row, not
// a row per link of the chain. Counting the owners' whole rows measured «is anything else stored
// anywhere», which was never the claim.*
$rows = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('settings') . ' WHERE owner_id IN (%d, %d, %d) AND setting_key = %s',
    $installation, $text->id, $edge->id, SettingKey::Renderer->value));
check('one written setting, one row', $rows === 1, "$rows rows");

echo "\n== 7. Bounding narrows, choosing is free ==\n";
$settings->put($chainType, SettingKey::Max->value, TypedValue::ofInt(5));
$settings->put($chainSite, SettingKey::Max->value, TypedValue::ofInt(2));
check('a maximum may be lowered', $settings->resolve($chainSite)[SettingKey::Max->value]->value->int === 2);

try { $settings->put($chainSite, SettingKey::Max->value, TypedValue::ofInt(9)); check('and may not be raised', false); }
catch (CannotWiden $e) { check('and may not be raised', true); }

// ⚠️ **Same guarantee, one key** ([D-405](../../docs/NewConcept/90-decision-log.md)). `mandatory` is
// gone: the multiplicity says it — `1..1` and `1..*` require one, `0..1` and `0..*` do not, and there
// is no fifth combination. *The floor of the multiplicity **is** mandatoriness, and it is edge-only,
// which is the owner's own reason for folding the two together.*
// ⚠️ **And the guarantee holds more strongly than the old rule did, for a reason worth writing
// down.** My first attempt here expected a `CannotWiden` when the floor was dropped — and it was
// **allowed**, because narrowing compares against what is **inherited** and overwriting your own
// value at the same link always may. *Multiplicity is **edge-only**, so there is never an
// ancestor multiplicity to narrow against.*
//
// ⚠️ **The guarantee is that there is only one place to say it.** `Resistor` inheriting `Value`
// from `Passiv` inherits the **same edge** — one edge, one multiplicity, no descendant copy. So a
// descendant cannot loosen an obligation because it has nowhere to say anything, which is a
// stronger form of [D-311](../../docs/NewConcept/90-decision-log.md) than the one-way rule was.
$settings->put($chainSite, SettingKey::Multiplicity->value, TypedValue::ofText('1..1'));
check(
    'a floor of one makes the attribute mandatory',
    Multiplicity::fromSetting($settings->resolve($chainSite)[SettingKey::Multiplicity->value]->value->text)->requiresOne()
);

$sub = $editor->createNode('__p4 Sub', $part->id);
$inherited = null;
foreach ($editor->fieldsOf($sub->id) as $one) { if ($one->id === $edge->id) { $inherited = $one; } }

check('a descendant inherits the very same edge, not a copy', $inherited !== null && $inherited->id === $edge->id);
check(
    'so it reads the same obligation and has nowhere to loosen it',
    $inherited !== null
        && Multiplicity::fromSetting($settings->resolve($settings->chainForUseSite($inherited))[SettingKey::Multiplicity->value]->value->text)->requiresOne()
);

$settings->put($chainType, SettingKey::DefaultValue->value, TypedValue::ofText('a'));
$settings->put($chainSite, SettingKey::DefaultValue->value, TypedValue::ofText('b'));
check('a default is free', $settings->resolve($chainSite)[SettingKey::DefaultValue->value]->value->text === 'b');

echo "\n== 7b. Multiplicity — four constants, on the edge (D-351) ==\n";
try {
    $settings->put($chainType, SettingKey::Multiplicity->value, TypedValue::ofText('1..1'));
    check('a node has no multiplicity', false);
} catch (SettingDoesNotApply $e) { check('a node has no multiplicity', true); }

try {
    $settings->put($chainSite, SettingKey::Multiplicity->value, TypedValue::ofText('3..7'));
    check('and 3..7 is not one of the four', false);
} catch (SettingDoesNotApply $e) { check('and 3..7 is not one of the four', true); }

$settings->put([$installation], SettingKey::Multiplicity->value, TypedValue::ofText('0..*'));
$settings->put($chainSite, SettingKey::Multiplicity->value, TypedValue::ofText('1..1'));
check('0..* narrows to 1..1', $settings->resolve($chainSite)[SettingKey::Multiplicity->value]->value->text === '1..1');

// ⚠️ Correcting your own override back to what is still inherited is NOT widening — the bound
// is what came from above, not what you last typed. Otherwise a mistake could only be undone
// through a reset, and D-312 never asked for that.
$settings->put($chainSite, SettingKey::Multiplicity->value, TypedValue::ofText('0..1'));
check('an own override may be corrected within the inherited bound',
    $settings->resolve($chainSite)[SettingKey::Multiplicity->value]->value->text === '0..1');

$settings->reset($edge->id, SettingKey::Multiplicity->value);
$settings->put([$installation], SettingKey::Multiplicity->value, TypedValue::ofText('1..1'));

try { $settings->put($chainSite, SettingKey::Multiplicity->value, TypedValue::ofText('0..*')); check('what is inherited narrow does not widen', false); }
catch (CannotWiden $e) { check('what is inherited narrow does not widen', true); }

$settings->reset($edge->id, SettingKey::Multiplicity->value);
$settings->put([$installation], SettingKey::Multiplicity->value, TypedValue::ofText('0..1'));

try { $settings->put($chainSite, SettingKey::Multiplicity->value, TypedValue::ofText('1..*')); check('0..1 and 1..* are incomparable, so neither replaces the other', false); }
catch (CannotWiden $e) { check('0..1 and 1..* are incomparable, so neither replaces the other', true); }

$settings->reset($installation, SettingKey::Multiplicity->value);

echo "\n== 8. Reserved names ==\n";
try { $settings->declareFree($chainType, 'renderer', TypedValue::ofText('plain')); check('an engine name is refused', false); }
catch (ReservedKey $e) { check('an engine name is refused', true); }
$settings->declareFree($chainType, '__p4 mine', TypedValue::ofText('yes'));
check('a name of its own is allowed', $settings->resolve($chainType)['__p4 mine']->value->text === 'yes');

echo "\n== 9. Typed columns, no stringly value ==\n";
$settings->put($chainType, SettingKey::Max->value, TypedValue::ofDecimal('2.50'));
$row = $wpdb->get_row($wpdb->prepare(
    'SELECT value_int, value_decimal, value_text FROM ' . Schema::table('settings') . '
     WHERE owner_id = %d AND setting_key = %s', $text->id, SettingKey::Max->value), ARRAY_A);
check('a decimal lands in value_decimal', $row['value_decimal'] !== null && $row['value_text'] === null, json_encode($row));
$back = $settings->resolve($chainType)[SettingKey::Max->value]->value->decimal;
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
// ⚠️ *Dieselbe Frage, andere Quelle: seit TASK-004 gibt es keine `identities` mehr, gegen die man
// prüfen könnte. Ein Eigentümer ist ein Knoten, eine Kante oder die Installation.*
$orphanSettings = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('settings') . ' s
     WHERE s.owner_id <> %d
       AND NOT EXISTS (SELECT 1 FROM ' . Schema::table('nodes') . ' n WHERE n.id = s.owner_id)
       AND NOT EXISTS (SELECT 1 FROM ' . Schema::table('relations') . ' r WHERE r.id = s.owner_id)',
    $installation
));
check('no setting hangs on an owner that never existed', $orphanSettings === 0, "$orphanSettings orphans");

echo "\n---- $ok passed, $bad failed ----\n";
exit($bad === 0 ? 0 : 1);
