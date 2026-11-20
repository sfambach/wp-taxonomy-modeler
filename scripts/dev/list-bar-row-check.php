<?php declare(strict_types=1);

/**
 * D-1462: the bar of a list is one row — bulk actions, the page's filters, «Filter row», «Columns», expand/collapse, the search
 * on the right and the count stand side by side, with WordPress's own admin styles, in a browser 1400 px wide, both in a full
 * admin page and beside the Logistik navigation. Architecture gate 18.11.: «Der Kopf hat drei Zeilen statt einer … Dein
 * list-head-check sollte prüfen, dass das Suchfeld und alle Bedienelemente im selben Zeilen-Container stehen (gleiches offsetTop
 * bei 1400 px).» Needs WordPress (for its markup helpers and stylesheets) and node with playwright.
 *
 *     php scripts/dev/list-bar-row-check.php [path/to/wordpress]
 *
 * @see docs/NewConcept/90-decision-log.md D-1462
 */

$root = $argv[1] ?? getenv('WP_ROOT') ?: '';

if ($root === '' || ! is_readable($root . '/wp-load.php')) {
    fwrite(STDERR, "Pass the WordPress folder as the first argument or WP_ROOT.\n");
    exit(2);
}

define('WP_USE_THEMES', false);
require $root . '/wp-load.php';
require __DIR__ . '/lib/no-write.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Taxmod\Core\Listing\ListColumn;
use Taxmod\Core\Listing\ListView;
use Taxmod\WordPress\Admin\ListPage;

$bad = 0;

function check(string $what, bool $passed, string $detail = ''): void
{
    global $bad;
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . ($passed || $detail === '' ? '' : " — $detail") . "\n";
    $bad += $passed ? 0 : 1;
}

$admins = get_users(['role' => 'administrator', 'number' => 1, 'fields' => 'ID']);
wp_set_current_user((int) ($admins[0] ?? 1));

$columns = [new ListColumn('image', 'Bild', fixed: true, width: '56px', filterable: false), new ListColumn('nr', 'Nr.', fixed: true, width: '6em'),
    new ListColumn('name', 'Material', fixed: true), new ListColumn('group', 'Gruppe'), new ListColumn('stock', 'Bestand', width: '7em', number: true),
    new ListColumn('min', 'Mindestbestand', width: '9em', number: true)];
$view   = new ListView($columns, null, 'smd');
$url    = static fn (array $change): string => admin_url('admin.php?page=taxmod-check');
// The longest a real bar gets: the Lager material list with its two choices and its longest count.
$filter = ListPage::filters('f', [['filter', ['' => 'Alle Materialien', 'low' => 'Unter Mindestbestand'], '', 'Filter'], ['group', ['' => 'Alle Gruppen', '1' => 'Material ▸ Bauteile ▸ Passiv ▸ Spulen'], '', 'Gruppe']]);
$bar    = ListPage::bulkBar('b', 'taxmod-check', ['print' => 'Etiketten drucken', 'move' => 'Umbuchen'], '194 Materialien im Lager',
    $filter . ListPage::filterToggle() . ListPage::columnMenu('check', $view, $url([])), true, [],
    ListPage::searchBox('f', 'smd', 'Material, Nummer, Gruppe …', ['page' => 'taxmod-check']));
$row    = ListPage::row('b', '1', ['', '44136', 'Spule 4,7 µH SMD', 'Material ▸ Bauteile', '0', '—'], '<a class="button button-small" href="#">Öffnen</a>', '');
$list   = $bar . ListPage::table(ListPage::heads($view, $url), $row, ListPage::filterRow('f', $view), true);

$styles = '';

foreach (['wp-includes/css/dashicons.css', 'wp-includes/css/buttons.css', 'wp-admin/css/common.css', 'wp-admin/css/forms.css', 'wp-admin/css/list-tables.css'] as $sheet) {
    if (is_readable($root . '/' . $sheet)) {
        $styles .= '<link rel="stylesheet" href="file://' . $root . '/' . $sheet . '">';
    }
}

$styles .= '<link rel="stylesheet" href="file://' . dirname(__DIR__, 2) . '/assets/admin.css">';

check('WordPress\'s admin styles are there to measure with', str_contains($styles, 'common.css') && str_contains($styles, 'list-tables.css'));

$dir  = sys_get_temp_dir() . '/taxmod-list-bar-' . getmypid();
@mkdir($dir);
// Beside the Logistik navigation the list has about 880 px of 1400; alone about 1200.
$page = static fn (int $width): string => '<!doctype html><html class="wp-toolbar"><head><meta charset="utf-8">' . $styles . '</head><body class="wp-admin wp-core-ui">'
    . '<div id="wpbody-content"><div class="wrap" style="width:' . $width . 'px">' . $list . '</div></div></body></html>';
file_put_contents($dir . '/full.html', $page(1200));
file_put_contents($dir . '/beside.html', $page(880));

$measure = <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
  const out = {};
  for (const name of ['full', 'beside']) {
    await page.goto('file://' + process.argv[2] + '/' + name + '.html');
    out[name] = await page.evaluate(() => {
      const bar = document.querySelector('.tablenav.top');
      const items = Array.from(bar.querySelectorAll('select, input[type="search"], .button:not(.screen-reader-text), .displaying-num'))
        .filter(e => e.offsetParent !== null && !e.closest('details form'));
      // A row: every control starts at the same height, and the count's text stands inside the band they span.
      const controls = items.filter(e => !e.classList.contains('displaying-num'));
      const tops = controls.map(e => Math.round(e.getBoundingClientRect().top));
      const band = [Math.min(...tops), Math.max(...controls.map(e => Math.round(e.getBoundingClientRect().bottom)))];
      const mid = e => { const r = e.getBoundingClientRect(); const m = Math.round(r.top + r.height / 2); return e.classList.contains('displaying-num') ? (m >= band[0] && m <= band[1] ? band[0] : m) : Math.round(r.top); };
      const search = bar.querySelector('input[name="s"]');
      const shown = Array.from(bar.querySelectorAll('input[type="submit"], button')).filter(e => e.offsetParent !== null && !e.closest('details form') && !e.classList.contains('screen-reader-text')).map(e => (e.value || e.textContent).trim());
      const right = Math.max(...items.map(e => e.getBoundingClientRect().right));
      // D-1527: every icon in a box sits in its middle — its glyph's line no taller than the icon, its middle on the box's.
      const icons = Array.from(bar.querySelectorAll('.button .dashicons, button .dashicons')).filter(e => e.offsetParent !== null).map(e => {
        const r = e.getBoundingClientRect(), box = e.closest('.button, button').getBoundingClientRect();
        return Math.max(parseFloat(getComputedStyle(e).lineHeight) - r.height, Math.abs((r.top + r.height / 2) - (box.top + box.height / 2)));
      });
      return { icons: icons, inside: right <= bar.getBoundingClientRect().right + 1, mids: Array.from(new Set(items.map(mid))), count: items.length, searchInBar: !!search,
        searchRight: search ? search.getBoundingClientRect().left > bar.getBoundingClientRect().left + bar.clientWidth / 2 : false, buttons: shown };
    });
  }
  console.log(JSON.stringify(out));
  await browser.close();
})().catch(e => { console.log(JSON.stringify({ error: String(e) })); });
JS;
file_put_contents($dir . '/measure.js', $measure);
$answer = json_decode((string) shell_exec('node ' . escapeshellarg($dir . '/measure.js') . ' ' . escapeshellarg($dir) . ' 2>&1'), true);

check('the browser measured', is_array($answer) && ! isset($answer['error']), is_array($answer) ? (string) ($answer['error'] ?? '') : 'no answer from node/playwright');

foreach (['full' => 'a full admin page', 'beside' => 'beside the Logistik navigation'] as $name => $where) {
    $m = $answer[$name] ?? ['inside' => false, 'mids' => [], 'count' => 0, 'searchInBar' => false, 'searchRight' => false, 'buttons' => []];
    $spread = $m['mids'] === [] ? 999 : max($m['mids']) - min($m['mids']);
    check("$where: every control of the bar stands in one row", $m['count'] > 5 && $spread <= 4, 'middles ' . implode(', ', $m['mids']));
    check("$where: nothing runs past the list's width", ($m['inside'] ?? false) === true);
    check("$where: the search stands in the bar, on the right", $m['searchInBar'] && $m['searchRight']);
    check("$where: every icon sits in the middle of its box (D-1527)", ($m['icons'] ?? []) !== [] && max($m['icons']) <= 2, 'offsets ' . implode(', ', array_map(static fn ($o): string => (string) round((float) $o, 1), $m['icons'] ?? [])));
    check("$where: no «Search» and no «Filter» button beside it", ! in_array('Search', $m['buttons'], true) && ! in_array('Filter', $m['buttons'], true), implode(' | ', $m['buttons']));
}

if (getenv('TAXMOD_KEEP') === false) {
    array_map('unlink', glob($dir . '/*') ?: []);
    @rmdir($dir);
}

exit($bad === 0 ? 0 : 1);
