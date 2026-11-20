<?php declare(strict_types=1);

/**
 * D-1488: ein Wähler im Dialog ist ein Feld mit dem Baumknopf darin; seine Liste klappt darunter auf und bleibt in der Breite des
 * Dialogs, die Fussleiste bleibt zu sehen, ihre Knöpfe sind gleich gross. Fehlt ein Satz, legt «+ Anlegen «Wort»» ihn an — als ein
 * Akt, den Undo zurücknimmt. Sein Wort 19.11. 20:48: «feld mit button integriert», «neuer hersteller ?»; 20:57 «Neuanlage
 * manufacturer nicht möglich». Prüftor 19.11.: «Wächter für Breite ≤ Dialog, sichtbare Fußleiste und Anlegen+Undo.»
 *
 * ```text
 * CONTRACT
 * Anlegen   der Endpunkt legt den Satz am Knoten an und nennt seinen Akt; undo_act nimmt ihn zurück
 * Browser   Dialog mit Knoten- und Satzwähler, 1200 × 800: beide Listen offen ≤ Dialogbreite, Fuss im Fenster, Knöpfe gleich breit
 * Browser   Suche ohne genauen Treffer → «+ … «Wort»»; mit genauem Treffer (Gross/klein egal) → keiner
 * Browser   Pfeil ab + Enter wählt und schliesst die Liste; Escape schliesst die Liste, nicht den Dialog
 * ```
 *
 * Usage: php scripts/dev/chooser-field-check.php — braucht node mit playwright.
 *
 * @see docs/NewConcept/90-decision-log.md D-1488
 */

define('WP_ADMIN', true);
define('WP_USE_THEMES', false);
define('DOING_AJAX', true);

$root = getenv('WP_ROOT') ?: 'C:/Devel/Wordpress';

require $root . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require __DIR__ . '/lib/beispiel.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

use Taxmod\Core\Model\Branch;
use Taxmod\WordPress\Admin\NodesScreen;
use Taxmod\WordPress\Persistence\Schema;

wp_set_current_user(1);

final class ChooserFieldStop extends RuntimeException
{
}

$failed = 0;
$say    = static function (bool $ok, string $what, string $detail = '') use (&$failed): void {
    printf("  %-4s %s%s\n", $ok ? 'ok' : 'FAIL', $what, $ok || $detail === '' ? '' : ' — ' . $detail);
    $failed += $ok ? 0 : 1;
};

$r      = new ReflectionClass(\Taxmod\WordPress\Plugin::class);
$plugin = $r->newInstanceWithoutConstructor();
$r->getProperty('file')->setValue($plugin, dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php');
$fw     = (new ReflectionMethod($plugin, 'frameworkNodes'))->invoke($plugin);

$text = taxmodSaatKnoten(Branch::DataTypes, 'Text');
$refs = taxmodBeispiel([
    ['op' => 'create_node', 'ref' => 'h', 'name' => '__cf Hersteller', 'parent' => $fw->rootOf(Branch::Model)->id],
    ['op' => 'add_field', 'ref' => 'n', 'node' => '@h', 'target' => $text, 'name' => '__cf Name', 'kind' => 'composition', 'multiplicity' => '1..1'],
    ['op' => 'create_record', 'ref' => 'r1', 'node' => '@h'],
    ['op' => 'set_values', 'record' => '@r1', 'values' => ['@n' => 'Texas Instruments']],
    ['op' => 'create_record', 'ref' => 'r2', 'node' => '@h'],
    ['op' => 'set_values', 'record' => '@r2', 'values' => ['@n' => 'NXP']],
    // D-1509: a main branch of its own beside Model, as Kontakt is since D-884.
    ['op' => 'create_node', 'ref' => 'k', 'name' => '__cf Kontakt', 'parent' => $fw->root()->id],
    ['op' => 'add_field', 'ref' => 'kn', 'node' => '@k', 'target' => $text, 'name' => '__cf Kontaktname', 'kind' => 'composition', 'multiplicity' => '1..1'],
]);

echo "\n== Anlegen und Undo ==\n";
add_filter('wp_die_ajax_handler', static fn (): callable => static function (): never {
    throw new ChooserFieldStop('json');
});

$_POST    = wp_slash(['node' => (string) $refs['h'], 'name' => '__cf Reichelt', '_ajax_nonce' => wp_create_nonce(NodesScreen::RECORD_CREATE_ACTION)]);
$_REQUEST = $_POST;
ob_start();

try {
    $plugin->screen()->handleRecordCreate();
} catch (ChooserFieldStop) {
    // The answer is in the buffer.
}

$antwort = json_decode((string) ob_get_clean(), true);
$neu     = (int) ($antwort['data']['id'] ?? 0);
$akt     = (int) ($antwort['data']['act'] ?? 0);
$da      = static fn (): bool => (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare('SELECT COUNT(*) FROM ' . Schema::table('node_records') . ' WHERE id = %d', $neu)) === 1;
$say(($antwort['success'] ?? false) === true && $neu > 0 && $da(), 'der Satz steht am Knoten', (string) json_encode($antwort));
$say($akt > 0, 'die Antwort nennt seinen Akt');
$undo = $akt > 0 ? wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'undo_act', 'act' => $akt]]]) : new WP_Error('x', 'kein Akt');
$say(! is_wp_error($undo) && ! $da(), 'Undo nimmt den angelegten Satz zurück', is_wp_error($undo) ? $undo->get_error_message() : '');

$anlegen = static function (int $node, string $name) use ($plugin): array {
    $_POST    = wp_slash(['node' => (string) $node, 'name' => $name, '_ajax_nonce' => wp_create_nonce(NodesScreen::RECORD_CREATE_ACTION)]);
    $_REQUEST = $_POST;
    ob_start();
    try {
        $plugin->screen()->handleRecordCreate();
    } catch (ChooserFieldStop) {
        // The answer is in the buffer.
    }

    return (array) json_decode((string) ob_get_clean(), true);
};
// D-1509, Stefan 20.11. 10:41 «kann nicht angelegt werden»: a contact chooser creates under the contact branch.
$kontakt = $anlegen((int) $refs['k'], '__cf Deutsche Post');
$say(($kontakt['success'] ?? false) === true, 'ein Satz entsteht auch unter einem eigenen Hauptast (Kontakt)', (string) json_encode($kontakt));
if (($kontakt['data']['act'] ?? 0) > 0) {
    wp_get_ability('taxmod/apply')->execute(['changes' => [['op' => 'undo_act', 'act' => (int) $kontakt['data']['act']]]]);
}
$wert = $anlegen($fw->rootOf(Branch::DataTypes)->id, '__cf nein');
$say(($wert['success'] ?? true) === false, 'unter einem Rahmenknoten entsteht keiner', (string) json_encode($wert));

echo "\n== Deutsch ==\n";
// Coordinator 19.11. 21:38 for him: the chooser's words in German — through the plugin's own translation (AR-2).
unload_textdomain('taxmod');
$geladen = load_textdomain('taxmod', dirname(__DIR__, 2) . '/languages/taxmod-de_DE.mo', 'de_DE');
$say($geladen && __('Create «%s»', 'taxmod') === '«%s» anlegen' && __('Choose: %s', 'taxmod') === 'Wählen: %s' && __('Cancel', 'taxmod') === 'Abbrechen', 'die deutschen Worte des Wählers sind geladen');
unload_textdomain('taxmod');
$say(str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/wp-taxonomy-modeler.php'), 'Domain Path:       /languages'), 'der Kopf nennt den Ordner der Übersetzungen');

echo "\n== Im Browser ==\n";
$wahl  = \Taxmod\WordPress\Admin\RecordPick::field('cf_hersteller', [['depth' => 0, 'name' => '__cf Hersteller', 'records' => [$refs['r1'] => 'Texas Instruments', $refs['r2'] => 'NXP'], 'node' => $refs['h']]], null, 'f1');
$knoten = (string) apply_filters('taxmod_node_chooser', '', ['name' => 'cf_gruppe', 'root' => $fw->rootOf(Branch::Model)->id, 'form' => 'f1']);
$say(str_contains($wahl, 'taxmod-record-new') && str_contains($knoten, 'taxmod-pick-tree'), 'beide Wähler sind gezeichnet');

$styles = '';

foreach (['wp-includes/css/dashicons.css', 'wp-includes/css/buttons.css', 'wp-admin/css/common.css', 'wp-admin/css/forms.css'] as $sheet) {
    $styles .= is_readable($root . '/' . $sheet) ? '<link rel="stylesheet" href="file://' . $root . '/' . $sheet . '">' : '';
}

$assets = dirname(__DIR__, 2) . '/assets';
$zeile  = static fn (string $wort, string $feld): string => '<div class="taxmod-form-row"><span class="taxmod-form-label">' . $wort . '</span><span class="taxmod-form-field">' . $feld . '</span></div>';
$seite  = '<!doctype html><html><head><meta charset="utf-8">' . $styles . '<link rel="stylesheet" href="file://' . $assets . '/admin.css"></head><body class="wp-admin wp-core-ui">'
    . '<form id="f1"></form><span class="taxmod-chooser"><input type="checkbox" class="taxmod-dialog-switch" id="cf-dialog" checked>'
    . '<span class="taxmod-dialog"><span class="taxmod-dialog-shade"></span><span class="taxmod-dialog-panel">'
    . '<span class="taxmod-dialog-head"><span class="taxmod-dialog-title">Neues Material anlegen</span></span>'
    . '<span class="taxmod-dialog-body"><div class="taxmod-form">' . $zeile('Gruppe', $knoten) . $zeile('Hersteller', $wahl) . '</div></span>'
    . '<span class="taxmod-dialog-foot"><button type="button" class="button button-primary">Anlegen</button><label class="button taxmod-dialog-cancel" for="cf-dialog">Abbrechen</label></span>'
    . '</span></span></span>'
    . '<script src="file://' . $assets . '/admin.js"></script></body></html>';

$dir = sys_get_temp_dir() . '/taxmod-chooser-field-' . getmypid();
@mkdir($dir);
file_put_contents($dir . '/page.html', $seite);
file_put_contents($dir . '/measure.js', <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1200, height: 800 } });
  await page.route('**/admin-post.php*', r => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: { id: 999999, word: 'Reichelt', act: 1 } }) }));
  await page.goto('file://' + process.argv[2] + '/page.html');
  const outer = '#cf-dialog ~ .taxmod-dialog > .taxmod-dialog-panel';
  const state = () => page.evaluate((o) => {
    const p = document.querySelector(o), r = p.getBoundingClientRect();
    const inner = [...p.querySelectorAll('.taxmod-chooser .taxmod-dialog-panel')].filter(x => x.offsetParent !== null).map(x => x.getBoundingClientRect());
    const wide = [...p.querySelectorAll('.taxmod-chooser .taxmod-dialog-panel *')].filter(x => x.offsetParent !== null).map(x => x.getBoundingClientRect().right);
    const foot = p.querySelector(':scope > .taxmod-dialog-foot').getBoundingClientRect();
    const sw = p.querySelector('.taxmod-record-pick .taxmod-dialog-switch'), c = p.querySelector('.taxmod-record-create');
    const tree = p.querySelector('.taxmod-pick .taxmod-pick-tree'), field = tree ? tree.parentNode.querySelector(':scope > label.taxmod-dialog-open, :scope > label.taxmod-record-dialog-open') : null;
    const tb = tree ? tree.getBoundingClientRect() : null, fb = field ? field.getBoundingClientRect() : null;
    return { open: inner.length, inside: inner.every(q => q.left >= r.left && q.right <= r.right + 0.5) && wide.every(x => x <= r.right + 0.5),
      foot: foot.bottom <= innerHeight && foot.height > 0, buttons: [...p.querySelectorAll(':scope > .taxmod-dialog-foot .button')].map(b => Math.round(b.getBoundingClientRect().width)),
      integrated: !!(tb && fb && tb.left >= fb.left && tb.right <= fb.right + 0.5), outer: document.getElementById('cf-dialog').checked, inner: sw.checked,
      create: c ? (c.hidden ? '' : c.textContent) : '', opener: p.querySelector('.taxmod-record-pick label.taxmod-record-dialog-open').textContent.trim() };
  }, outer);
  const out = {};
  out.closed = await state();
  await page.click(outer + ' .taxmod-record-pick .taxmod-pick-tree');
  await page.click(outer + ' .taxmod-form-row:first-child .taxmod-pick-tree');
  out.both = await state();
  await page.click(outer + ' .taxmod-form-row:first-child .taxmod-pick-tree');
  const s = outer + ' .taxmod-record-pick .taxmod-record-search';
  await page.fill(s, 'Reichelt'); out.missing = await state();
  await page.fill(s, 'nxp'); out.exact = await state();
  await page.press(s, 'ArrowDown'); await page.press(s, 'Enter'); await page.waitForTimeout(50); out.picked = await state();
  await page.click(outer + ' .taxmod-record-pick .taxmod-pick-tree'); await page.press(s, 'Escape'); await page.waitForTimeout(50); out.escape = await state();
  await page.click(outer + ' .taxmod-record-pick .taxmod-pick-tree'); await page.fill(s, 'Reichelt'); await page.press(s, 'ArrowDown'); await page.press(s, 'Enter');
  await page.waitForTimeout(200); out.created = await state();
  console.log(JSON.stringify(out));
  await browser.close();
})().catch(e => { console.log(JSON.stringify({ error: String(e) })); });
JS);

$m = json_decode((string) shell_exec('node ' . escapeshellarg($dir . '/measure.js') . ' ' . escapeshellarg($dir) . ' 2>&1'), true);
$say(is_array($m) && ! isset($m['error']), 'der Browser hat gemessen', is_array($m) ? (string) ($m['error'] ?? '') : 'keine Antwort von node/playwright');
$m = is_array($m) && ! isset($m['error']) ? $m : [];

$say(($m['closed']['open'] ?? 1) === 0 && ($m['closed']['integrated'] ?? false), 'zu: nur das Feld, der Baumknopf steht darin');
$say(($m['both']['open'] ?? 0) === 2 && ($m['both']['inside'] ?? false), 'beide Listen offen: nichts reicht über den Dialog hinaus');
$say(($m['both']['foot'] ?? false) === true, 'die Fussleiste bleibt im Fenster');
$say(count(array_unique($m['both']['buttons'] ?? [0, 1])) === 1, 'die Knöpfe der Fussleiste sind gleich breit', implode(' / ', $m['both']['buttons'] ?? []));
$say(str_starts_with($m['missing']['create'] ?? '', '+ ') && str_contains($m['missing']['create'] ?? '', '«Reichelt»') && ! str_contains($m['missing']['create'] ?? '', '%s'), 'ohne genauen Treffer: «+ … «Reichelt» …»', (string) ($m['missing']['create'] ?? ''));
$say(($m['exact']['create'] ?? 'x') === '', 'mit genauem Treffer (nxp = NXP): kein Anlegen');
$say(($m['picked']['opener'] ?? '') === 'NXP' && ($m['picked']['inner'] ?? true) === false, 'Pfeil + Enter wählt und schliesst die Liste');
$say(($m['escape']['inner'] ?? true) === false && ($m['escape']['outer'] ?? false) === true, 'Escape schliesst die Liste, nicht den Dialog');
$say(($m['created']['opener'] ?? '') === 'Reichelt' && ($m['created']['inner'] ?? true) === false, 'Anlegen aus der Suche: der neue Satz ist gewählt');

if (getenv('TAXMOD_KEEP') === false) {
    array_map('unlink', glob($dir . '/*') ?: []);
    @rmdir($dir);
}

echo $failed === 0 ? "chooser-field-check: ok\n" : "chooser-field-check: {$failed} FAIL\n";
exit($failed === 0 ? 0 : 1);
