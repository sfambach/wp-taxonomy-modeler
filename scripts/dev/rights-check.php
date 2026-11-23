<?php declare(strict_types=1);
/**
 * Rights, roles and what a person sees (D-1585, D-1586): the one table, the shipped roles, the administrator's rights, the
 * menu and the bulk actions drawn by the right their handler checks — and a ratchet: `manage_options` and the modeller's
 * bridge `Plugin::CAPABILITY` in the modules, per repository, only ever lowered; in the modeller none outside the table.
 *
 *     php scripts/dev/rights-check.php [path/to/wordpress]
 *
 * @see docs/NewConcept/90-decision-log.md D-1585, D-1586
 */

const ROOT = __DIR__ . '/../..';

/** Measured 2026-11-23 on main 191ba3d1; a ceiling only falls, as each module checks its own rights (D-1585 step 3). */
const MODULE_CEILINGS = [
    'wp_tax_api_amazon'      => 1,
    'wp_tax_api_dhl'         => 1,
    'wp_tax_api_ebay'        => 1,
    'wp_tax_api_gls'         => 1,
    'wp_tax_api_jlcpcb'      => 1,
    'wp_tax_ebay'            => 1,
    'wp_tax_label'           => 19,
    'wp_tax_log_sales'       => 4,
    'wp_tax_mail'            => 2,
    'wp_tax_manufacturing'   => 5,
    'wp_tax_shipping'        => 7,
    'wp_tax_stock'           => 77,
];

const OLD_RIGHT = '/\'manage_options\'|\bModeller::CAPABILITY\b|\\\\Taxmod\\\\WordPress\\\\Plugin::CAPABILITY/';

$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $bad;
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . ($passed || $detail === '' ? '' : " — $detail") . "\n";
    $bad += $passed ? 0 : 1;
}

/** @return list<string> */
function phpFiles(string $dir): array
{
    $out = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $out[] = $file->getPathname();
        }
    }

    sort($out);

    return $out;
}

function withoutComments(string $code): string
{
    $out = '';

    foreach (token_get_all($code) as $token) {
        $out .= is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token;
    }

    return $out;
}

echo "The modeller names its rights from the table\n";

$outside = [];
$menus   = [];

foreach (phpFiles(ROOT . '/src') as $file) {
    $code = withoutComments((string) file_get_contents($file));
    $name = substr($file, strlen(ROOT) + 1);

    if (! str_ends_with($file, '/src/WordPress/Rights.php') && str_contains($code, "'manage_options'")) {
        $outside[] = $name . ' (manage_options)';
    }

    // The bridge is defined in Plugin.php and nowhere used by the modeller itself.
    if (preg_match_all('/\b(?:self|Plugin|\\\\Taxmod\\\\WordPress\\\\Plugin)::CAPABILITY\b/', $code) > 0) {
        $outside[] = $name . ' (Plugin::CAPABILITY)';
    }

    preg_match_all('/add_(?:sub)?menu_page\((.*?)\);/s', $code, $calls);

    foreach ($calls[1] as $call) {
        if (! preg_match('/Rights::[A-Z_]+/', $call)) {
            $menus[] = $name;
        }
    }
}

check("no 'manage_options' and no Plugin::CAPABILITY in the modeller outside the table", $outside === [], implode(', ', $outside));
check('every menu entry of the modeller names a right of the table (D-1586)', $menus === [], implode(', ', array_unique($menus)));

$probe = withoutComments("<?php if (current_user_can('manage_options')) {} // 'manage_options'\n");
check('the count sees a call and not a comment (probe)', preg_match_all(OLD_RIGHT, $probe) === 1);

echo "\nThe modules follow, never back (ratchet)\n";

foreach (MODULE_CEILINGS as $repo => $ceiling) {
    $dir = dirname(realpath(ROOT) ?: ROOT) . '/' . $repo . '/src';

    if (! is_dir($dir)) {
        echo "  --   $repo not beside the modeller, not counted\n";

        continue;
    }

    $count = 0;

    foreach (phpFiles($dir) as $file) {
        $count += preg_match_all(OLD_RIGHT, withoutComments((string) file_get_contents($file)));
    }

    check("$repo: old rights $count ≤ $ceiling", $count <= $ceiling, 'a new check of manage_options; name a right of the table');

    if ($count < $ceiling) {
        echo "  NOTE $repo fell to $count — lower its ceiling\n";
    }
}

$root = $argv[1] ?? getenv('WP_ROOT') ?: '';

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "\nPass the WordPress folder as the first argument or WP_ROOT for the run against WordPress.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
define('WP_ADMIN', true);
require $root . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use Taxmod\WordPress\Admin\ListPage;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Rights;

echo "\nThe table and the roles\n";

$table = array_keys(Rights::table());
check('the table holds the rights of D-1585', $table === ['taxmod_view', 'taxmod_write_data', 'taxmod_model', 'taxmod_configure', 'taxmod_stock_book', 'taxmod_purchase', 'taxmod_manufacture', 'taxmod_ship', 'taxmod_sell', 'taxmod_app_only'], implode(', ', $table));
check('printing is no right of its own', ! in_array('taxmod_print', $table, true));

delete_option(Rights::ROLES_VERSION_OPTION);
add_role('taxmod_stock', 'old stock', ['read' => true, 'taxmod_model' => true, 'edit_posts' => true]);
$editorBefore = get_role('editor')?->capabilities;
Rights::ensureRoles();

foreach (Rights::roles() as $slug => $role) {
    $role_ = get_role($slug);
    $taxmod = $role_ === null ? [] : array_keys(array_filter($role_->capabilities, static fn (mixed $on, string $cap): bool => $on && str_starts_with($cap, 'taxmod_'), ARRAY_FILTER_USE_BOTH));
    sort($taxmod);
    $want = $role['rights'];
    sort($want);
    check("role $slug carries exactly its rights", $taxmod === $want, implode(', ', $taxmod));
}

check('a right the table took away is removed from an existing role', empty(get_role('taxmod_stock')?->capabilities['taxmod_model']));
check('what is not taxmod stays on an existing role', ! empty(get_role('taxmod_stock')?->capabilities['edit_posts']));
check('other roles are not touched', get_role('editor')?->capabilities === $editorBefore);
check('no shipped role carries «only taxmod»', array_filter(Rights::roles(), static fn (array $r): bool => in_array(Rights::APP_ONLY, $r['rights'], true)) === []);
$version = get_option(Rights::ROLES_VERSION_OPTION);
Rights::ensureRoles();
check('a second run with the same table changes nothing', get_option(Rights::ROLES_VERSION_OPTION) === $version && $version !== '');

echo "\nWho holds what\n";

$admin = (int) (get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID'])[0] ?? 1);
$reader = wp_insert_user(['user_login' => '__rights_reader', 'user_pass' => wp_generate_password(), 'role' => 'taxmod_read_only']);
$stock  = wp_insert_user(['user_login' => '__rights_stock', 'user_pass' => wp_generate_password(), 'role' => 'taxmod_stock']);
check('test users stand', is_int($reader) && is_int($stock), is_wp_error($reader) ? $reader->get_error_message() : '');

$missing = array_filter($table, static fn (string $r): bool => $r !== Rights::APP_ONLY && ! user_can($admin, $r));
check('the administrator holds every right', $missing === [], implode(', ', $missing));
check('the administrator is no taxmod user by itself', ! user_can($admin, Rights::APP_ONLY));
check('read only: sees', user_can($reader, Rights::VIEW));
check('read only: writes nothing, shapes nothing, configures nothing', ! user_can($reader, Rights::WRITE_DATA) && ! user_can($reader, Rights::MODEL) && ! user_can($reader, Rights::CONFIGURE));
check('stock: sees, writes, books — and nothing of purchasing or the model', user_can($stock, Rights::STOCK_BOOK) && user_can($stock, Rights::WRITE_DATA) && ! user_can($stock, Rights::PURCHASE) && ! user_can($stock, Rights::MODEL));
check('read only: no manage_options', ! user_can($reader, 'manage_options'));

add_filter(Rights::FILTER, static fn (array $rights): array => $rights + ['taxmod_probe_right' => 'Probe']);
(new ReflectionProperty(Rights::class, 'all'))->setValue(null, null);
check('a module adds its right through the filter, and the administrator holds it', isset(Rights::all()['taxmod_probe_right']) && user_can($admin, 'taxmod_probe_right'));
check('a filtered right without the prefix is ignored', ! isset((static function (): array {
    add_filter(Rights::FILTER, static fn (array $r): array => $r + ['edit_users' => 'no']);
    (new ReflectionProperty(Rights::class, 'all'))->setValue(null, null);

    return Rights::all();
})()['edit_users']));

echo "\nWhat a person sees is what the handler allows (D-1586)\n";

check('a record act needs the right to write data', NodesScreen::rightOf('save_record') === Rights::WRITE_DATA && NodesScreen::rightOf('add_part') === Rights::WRITE_DATA);
check('filtering records needs the right to see', NodesScreen::rightOf('filter_records') === Rights::VIEW);
check('every other act of the node page shapes the model', NodesScreen::rightOf('rename') === Rights::MODEL && NodesScreen::rightOf('add_field') === Rights::MODEL);

$abilities = static fn (string $name, array $input = []): bool => (bool) wp_get_ability($name)?->check_permissions($input);
wp_set_current_user($reader);
check('read only: may read the tree, may not write data or apply', $abilities('taxmod/read-tree', ['root' => 1]) && ! $abilities('taxmod/write-data', ['changes' => []]) && ! $abilities('taxmod/apply', ['changes' => []]));

$actions = ['keep' => 'Keep', 'shape' => ['Shape', Rights::MODEL]];
$bar     = ListPage::bulkBar('rights-probe', 'rights_probe', $actions, '');
check('read only: an action without its right is not drawn', str_contains($bar, 'value="keep"') && ! str_contains($bar, 'value="shape"'));
check('read only: and its handler refuses it with the same list', ListPage::mayAct($actions, 'keep') && ! ListPage::mayAct($actions, 'shape') && ! ListPage::mayAct($actions, 'unknown'));

global $menu, $submenu;
$menu = $submenu = [];
do_action('admin_menu', '');
$entries = array_map(static fn (array $entry): array => [$entry[2], $entry[1]], $submenu['taxmod'] ?? []);
$notTable = array_filter($entries, static fn (array $e): bool => ! isset(Rights::all()[$e[1]]));
check('every entry under the taxmod menu names a right, not manage_options', $notTable === [], wp_json_encode(array_values($notTable)));
$seen = array_values(array_map(static fn (array $e): string => $e[0], array_filter($entries, static fn (array $e): bool => current_user_can($e[1]))));
check('read only: of the modeller\'s entries sees print jobs, not the model, configuration, cleanup, backup, changes or the inbox', in_array('taxmod-print-jobs', $seen, true) && array_intersect($seen, ['taxmod', 'taxmod-settings', 'taxmod-cleanup', 'taxmod-backup', 'taxmod-changes', 'taxmod-inbox']) === [], implode(', ', $seen));

wp_set_current_user($admin);
$bar = ListPage::bulkBar('rights-probe', 'rights_probe', $actions, '');
check('the administrator: both actions drawn', str_contains($bar, 'value="keep"') && str_contains($bar, 'value="shape"'));

echo $bad === 0 ? "\nAll green.\n" : "\n$bad failed.\n";
exit($bad === 0 ? 0 : 1);
