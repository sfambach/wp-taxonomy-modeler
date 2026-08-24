<?php declare(strict_types=1);
/**
 * Package 5 acceptance check — labels, roles and locales, against a real database.
 *
 *     php scripts/dev/package5-check.php [path/to/wordpress]
 *
 * The owner's test: the same thing is called something else in English.
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
use Taxmod\Core\Model\Label;
use Taxmod\Core\Model\SeededRole;
use Taxmod\Core\Service\Labels;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbLabelRepository;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
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

$editor = new ModelEditor($nodes, $edges, $ids, $framework, $log);
$stored = new WpdbLabelRepository();
$labels = new Labels($stored, $framework);

echo "\n== 1. The roles are nodes, and they sit in no data branch ==\n";
foreach (SeededRole::cases() as $role) {
    $id   = $framework->roleId($role);
    $node = $id === 0 ? null : $nodes->find($id);
    check("{$role->value} is a node", $node !== null && $node->name === $role->value);
    check("  and points at no branch", $node !== null && $framework->branchOf($node) === null);
}

echo "\n== 2. The chain ==\n";
$thing = $editor->createNode('__p5 Widerstandswert', $framework->rootOf(Branch::Model)->id);

check('with nothing stored, the node name is used', $labels->of($thing, SeededRole::Form) === '__p5 Widerstandswert');

$stored->put(new Label($thing->id, '', $framework->roleId(SeededRole::Help), 'one', '', '__p5 the long description'));
check('a missing role falls through to help', $labels->of($thing, SeededRole::Table) === '__p5 the long description');

$stored->put(new Label($thing->id, '', $framework->roleId(SeededRole::Table), 'one', '', 'R'));
check('and the asked-for role wins once it exists', $labels->of($thing, SeededRole::Table) === 'R');

echo "\n== 3. The same thing in another language ==\n";
$form = $framework->roleId(SeededRole::Form);
$stored->put(new Label($thing->id, '', $form, 'one', 'de_DE', '__p5 Widerstandswert'));
$stored->put(new Label($thing->id, '', $form, 'one', 'en_US', '__p5 Resistance value'));

check('German', $labels->of($thing, SeededRole::Form, 'de_DE') === '__p5 Widerstandswert');
check('English', $labels->of($thing, SeededRole::Form, 'en_US') === '__p5 Resistance value');

$stored->put(new Label($thing->id, '', $form, 'one', '', '__p5 neutral'));
check('a locale nobody wrote falls back to the neutral row', $labels->of($thing, SeededRole::Form, 'fr_FR') === '__p5 neutral');

echo "\n== 4. Number before role ==\n";
$stored->put(new Label($thing->id, '', $form, 'other', 'de_DE', '__p5 Widerstandswerte'));
check('the plural form is used when stored', $labels->of($thing, SeededRole::Form, 'de_DE', 'other') === '__p5 Widerstandswerte');
check('and falls back to the base form of the same role', $labels->of($thing, SeededRole::Form, 'en_US', 'other') === '__p5 Resistance value');

echo "\n== 5. A label hangs on an identity, so an edge can carry one ==\n";
$text = $editor->createNode('__p5 Text', $framework->rootOf(Branch::DataTypes)->id);
$edge = $editor->addAttribute($thing->id, $text->id, '__p5 description');
$stored->put(new Label($edge->id, '', $form, 'one', 'de_DE', '__p5 Beschreibung'));

$onEdge = $stored->forOwners([$edge->id]);
check('the edge has its own label', count($onEdge) === 1 && $onEdge[0]->text === '__p5 Beschreibung');
check('and it did not land on the type', count(array_filter($stored->forOwners([$text->id]))) === 0);

echo "\n== 6. One row per owner, path, role, number and locale ==\n";
$stored->put(new Label($thing->id, '', $form, 'one', 'de_DE', '__p5 zweimal geschrieben'));
$rows = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT COUNT(*) FROM ' . Schema::table('labels') . ' WHERE owner_id = %d AND role_id = %d AND number = %s AND locale = %s',
    $thing->id, $form, 'one', 'de_DE'));
check('writing twice leaves one row', $rows === 1, "$rows rows");
check('and the second write won', $labels->of($thing, SeededRole::Form, 'de_DE') === '__p5 zweimal geschrieben');

echo "\n== 7. The check cleans up after itself ==\n";
foreach ([$thing->id, $text->id] as $scratch) {
    $node = $nodes->find($scratch);
    if ($node !== null) { $edges->purgeEdgesTouching($node->id); $nodes->purgeSubtree($node); }
}
$wpdb->query('DELETE FROM ' . Schema::table('labels') . ' WHERE text LIKE "__p5%"');
$wpdb->query('DELETE FROM ' . Schema::table('relations') . ' WHERE name LIKE "__p5%"');
$wpdb->query('DELETE FROM ' . Schema::table('changelog') . ' WHERE after_state LIKE "%__p5%"');
$left = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('nodes') . ' WHERE name LIKE "__p5%"');
check('scratch nodes are gone', $left === 0, "$left left");
$orphans = (int) $wpdb->get_var(
    'SELECT COUNT(*) FROM ' . Schema::table('labels') . ' l
     LEFT JOIN ' . Schema::table('identities') . ' i ON i.id = l.owner_id
     WHERE i.id IS NULL'
);
check('no label hangs on an identity that never existed', $orphans === 0, "$orphans orphans");

echo "\n---- $ok passed, $bad failed ----\n";
exit($bad === 0 ? 0 : 1);
