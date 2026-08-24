<?php declare(strict_types=1);
/**
 * Development reset — throw away everything this plugin owns and start from a clean install.
 *
 *     php scripts/dev/reset.php --yes [path/to/wordpress]
 *
 * ⚠️ **All eight tables go together, and that is the whole point.** Resetting `identities` on
 * its own would leave changelog rows, settings and labels pointing at numbers that are about to
 * be handed out again — exactly the silent mis-attachment D-339 and D-340 exist to prevent. A
 * reset is only safe when nothing survives that could refer to an old id.
 *
 * ⚠️ **Command line only.** Not a hook, not an admin screen, not a REST route. Something that
 * destroys a model must not be one mistyped URL away.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This is a command-line tool.\n");
}

$argsGiven = array_slice($argv, 1);
$confirmed = in_array('--yes', $argsGiven, true);
$paths     = array_values(array_filter($argsGiven, static fn (string $a): bool => $a !== '--yes'));

if (! $confirmed) {
    fwrite(STDERR, "Refusing to wipe anything without --yes.\n");
    exit(2);
}

$root = $paths[0] ?? getenv('WP_ROOT') ?: null;

if ($root === null) {
    $dir = getcwd();
    while ($dir !== '' && ! is_readable($dir . '/wp-load.php')) {
        $up  = dirname($dir);
        $dir = $up === $dir ? '' : $up;
    }
    $root = $dir;
}

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Cannot find wp-load.php. Pass the WordPress folder as an argument.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\WordPress\Persistence\Schema;
use Taxmod\WordPress\Persistence\SeededFrameworkNodes;
use Taxmod\WordPress\Persistence\TableIdentityAllocator;
use Taxmod\WordPress\Persistence\WpdbChangelog;
use Taxmod\WordPress\Persistence\WpdbNodeRepository;
use Taxmod\WordPress\SystemClock;

global $wpdb;

$before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::table('nodes'));
echo "Dropping everything. {$before} nodes go with it.\n";

// Foreign keys make the order matter, and dropping is easier to reason about than
// disabling the checks — a fresh CREATE also clears the AUTO_INCREMENT, which is the
// point of a reset.
$wpdb->query('SET FOREIGN_KEY_CHECKS = 0');

foreach (Schema::tableNames() as $name) {
    $wpdb->query('DROP TABLE IF EXISTS ' . Schema::table($name));
}

$wpdb->query('SET FOREIGN_KEY_CHECKS = 1');

delete_option(Schema::VERSION_OPTION);
delete_option(SeededFrameworkNodes::ROOT_OPTION);
delete_option(SeededFrameworkNodes::TRASH_OPTION);

Schema::install();
update_option(Schema::VERSION_OPTION, Schema::VERSION, true);

$framework = new SeededFrameworkNodes(
    new WpdbNodeRepository(),
    new TableIdentityAllocator(),
    new WpdbChangelog(new SystemClock())
);
$framework->seed();

printf(
    "Reinstalled. Root is #%d, trash is #%d, next id will be %d.\n",
    $framework->root()->id,
    $framework->trash()->id,
    (int) $wpdb->get_var('SELECT MAX(id) + 1 FROM ' . Schema::table('identities'))
);
