<?php declare(strict_types=1);
/**
 * Package 1 acceptance check — the owner's own test, automated.
 *
 * Create, rename, send to trash, read it back from a fresh query. Run it from the command line:
 *
 *     php scripts/dev/package1-check.php [path/to/wordpress]
 *
 * ⚠️ It finds WordPress by argument, by WP_ROOT, or by walking up from the working directory —
 * never by walking up from __DIR__, because the plugin is reached through a junction and PHP
 * resolves that to the source checkout, which is outside the docroot.
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

$plugin = dirname(__DIR__, 2);
require $plugin . '/vendor/autoload.php';

use Taxmod\Core\Exception\DomainError;
use Taxmod\Core\Service\ModelEditor;
use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\Persistence\WpdbRelationRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;
$ok = 0;
$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $ok, $bad;
    if ($passed) { $ok++; echo "  OK   $what\n"; }
    else { $bad++; echo "  FAIL $what" . ($detail !== '' ? " — $detail" : '') . "\n"; }
}

echo "\n== 1. Tables ==\n";
Schema::install();
update_option(Schema::VERSION_OPTION, Schema::VERSION, true);

foreach (Schema::tableNames() as $name) {
    $table = Schema::table($name);
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    check($table, $found === $table, $wpdb->last_error);
}

echo "\n== 2. Framework nodes ==\n";
$nodes = new WpdbNodeRepository();
$log = new WpdbChangelog(new SystemClock());
$edges = new WpdbRelationRepository();
$framework = new SeededFrameworkNodes($nodes, $edges, $log);
$framework->seed();

$root = $framework->root();
$trash = $framework->trash();
check('root exists, path is its own id', $root->path === (string) $root->id, $root->path);
check('trash sits under the root', $trash->path === $root->path . '.' . $trash->id, $trash->path);
check('root is protected', $framework->isProtected($root));

// ⚠️ **Die Zusage hat sich mit TASK-004 geändert, und das ist Absicht** (`PR-9`): *bis Fassung 20
// zog jede Tabelle ihre Nummer aus `identities`, und dieser Abschnitt prüfte genau diesen einen
// Zähler. **`identities` ist gestrichen**, jede Tabelle vergibt selbst
// ([`package.md` §6](../../../docs/pakete/modelltabellen/package.md)) — geprüft wird darum jetzt,
// dass jede Tabelle einen eigenen, aufsteigenden Zähler hat. Was der alte Abschnitt hielt, hält
// vollständig `id-space-check.php`.*
echo "\n== 3. Jede Tabelle hat ihren eigenen Id-Raum ==\n";
check(
    'identities ist weg',
    $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'taxmod_identities')) === null
);

// ⚠️ *Der Zähler kommt aus `SHOW CREATE TABLE`: MySQL 8 hält die Zahl in `information_schema`
// zwischengespeichert und gibt dort `NULL` zurück, obwohl die Tabelle einen Zähler hat. Dieselbe
// Lesart wie in [`id-space-check.php`](id-space-check.php).*
$zaehlerVon = static function (string $tabelle) use ($wpdb): int {
    $sql     = ($wpdb->get_row("SHOW CREATE TABLE {$tabelle}", ARRAY_N) ?: [1 => ''])[1];
    $treffer = [];

    if (preg_match('/AUTO_INCREMENT=(\d+)/', (string) $sql, $treffer) === 1) {
        return (int) $treffer[1];
    }

    return (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) + 1 FROM {$tabelle}");
};

foreach (['nodes', 'relations'] as $name) {
    $zaehler  = $zaehlerVon(Schema::table($name));
    $hoechste = (int) $wpdb->get_var('SELECT COALESCE(MAX(id), 0) FROM ' . Schema::table($name));

    check("{$name} vergibt selbst und zaehlt vorwaerts", $zaehler > $hoechste, "$zaehler > $hoechste");
}

echo "\n== 4. Create, rename, trash ==\n";
$editor = new ModelEditor($nodes, $edges, $framework, $log);

$made = $editor->createNode('  Platine  ', $root->id);
check('name is trimmed on the way in', $made->name === 'Platine', "«{$made->name}»");
check('version starts at 1', $made->version === 1);
check('path hangs off the root', $made->path === $root->path . '.' . $made->id, $made->path);

$renamed = $editor->rename($made->id, 'Board');
check('rename raises the version', $renamed->version === 2, (string) $renamed->version);

$again = $editor->rename($made->id, 'Board');
check('an unchanged save does NOT raise it (D-282)', $again->version === 2, (string) $again->version);

$child = $editor->createNode('Resistor', $made->id);
check('a child hangs off its parent', $child->path === $renamed->path . '.' . $child->id, $child->path);

$parked = $editor->moveToTrash($made->id);
check('parked under the trash', $parked->path === $trash->path . '.' . $parked->id, $parked->path);

$movedChild = $nodes->byId($child->id);
check('the child moved with it', $movedChild->path === $parked->path . '.' . $child->id, $movedChild->path);

echo "\n== 5. Refusals ==\n";
try { $editor->createNode('   ', $root->id); check('an empty name is refused', false); }
catch (DomainError $e) { check('an empty name is refused', true); }

try { $editor->moveToTrash($root->id); check('the root cannot be trashed', false); }
catch (DomainError $e) { check('the root cannot be trashed', true); }

echo "\n== 6. It survives a fresh read ==\n";
$fresh = (new WpdbNodeRepository())->byId($made->id);
check('still there, still named Board', $fresh->name === 'Board', $fresh->name);
check('still parked', $fresh->path === $parked->path, $fresh->path);

echo "\n== 7. Changelog ==\n";
$rows = $wpdb->get_results($wpdb->prepare(
    'SELECT what FROM ' . Schema::table('changelog') . ' WHERE owner_id = %d ORDER BY id',
    $made->id
), ARRAY_A);
$what = array_column($rows ?: [], 'what');
check('created / renamed / parked, and no entry for the unchanged save',
    $what === ['created', 'renamed', 'parked'], implode(', ', $what));


echo "\n== 8. The check cleans up after itself ==\n";
// A check that leaves its scratch nodes behind is a check nobody dares run twice against
// real data. It removes exactly what it made, by id, and nothing else.
foreach ([$child->id, $made->id] as $scratch) {
    $node = $nodes->find($scratch);
    if ($node !== null) { $nodes->purgeSubtree($node); }
    $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::table('changelog') . ' WHERE owner_id = %d', $scratch));
}
check('scratch nodes are gone', $nodes->find($made->id) === null && $nodes->find($child->id) === null);
// ⚠️ *Dieselbe Zusage wie bisher, an der Stelle, die sie seit TASK-004 hält: der Zähler von `nodes`
// steht über beiden Nummern, also kann keine von beiden noch einmal vergeben werden ([D-340]).*
check('their ids stay spent', $zaehlerVon(Schema::table('nodes')) > max($made->id, $child->id));

echo "\n---- $ok passed, $bad failed ----\n";
exit($bad === 0 ? 0 : 1);
