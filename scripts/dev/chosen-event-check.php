<?php declare(strict_types=1);

/**
 * Auswahl = Ereignis (D-1551) as a failing check. Two halves:
 *
 * 1. **The building block works**, measured in a browser on a small page with `assets/live.js` and `assets/admin.js`: a
 *    select, a checkbox and a radio say `taxmod:chosen` at their form, typing does not; a choice in a dialog with «OK» is
 *    said once on «OK» and not at all on «Abbrechen»; a value a script writes is said through `taxmodChosen`; an element that
 *    follows the field is fetched with the choice and put in place without a reload.
 * 2. **A ratchet** (PR-9): raw `change` listeners and `submit()` / `requestSubmit()` calls in `assets/*.js` of this repository
 *    and every wp_tax_* beside it may not grow. A file over its count, or an unlisted file with any, fails. Moving a place onto
 *    `taxmod:chosen` lowers its count in the same commit; the list is never raised. The block's own listener is not counted.
 *
 *     php scripts/dev/chosen-event-check.php
 *
 * Needs no WordPress; needs node with playwright for the first half.
 *
 * @see docs/NewConcept/90-decision-log.md D-1551
 */

/** Counted 2026-11-21 on main of each repository; only ever lowered. */
const CHANGE_PENDING = [
    'wp-taxonomy-modeler/assets/admin.js' => 12,
    'wp-taxonomy-modeler/assets/list-head.js' => 2,
    'wp-taxonomy-modeler/assets/live.js' => 1,
    'wp-taxonomy-modeler/assets/shown-by.js' => 1,
    'wp-taxonomy-modeler/assets/view-dialog.js' => 1,
    'wp_tax_label/assets/editor.js' => 12,
    'wp_tax_label/assets/label62.js' => 2,
    'wp_tax_manufacturing/assets/manufacturing.js' => 1,
];

/** The same for a form sent by a script; only ever lowered. */
const SUBMIT_PENDING = [
    'wp-taxonomy-modeler/assets/admin.js' => 9,
    'wp-taxonomy-modeler/assets/confirm-dialog.js' => 2,
    'wp-taxonomy-modeler/assets/live.js' => 2,
    'wp_tax_manufacturing/assets/assign.js' => 2,
];

const BLOCK_LISTENER = "document.addEventListener( 'change', chosenFromChange, true );";

$self   = dirname(__DIR__, 2);
$failed = 0;

function claim(string $what, bool $holds, string $detail = ''): void
{
    global $failed;
    echo ($holds ? 'ok   ' : 'FAIL ') . $what . ($holds || $detail === '' ? '' : ' — ' . $detail) . "\n";
    $failed += $holds ? 0 : 1;
}

// ---- 1. the building block, in a browser -------------------------------------------------------------------------------

$live  = (string) @file_get_contents($self . '/assets/live.js');
$admin = (string) @file_get_contents($self . '/assets/admin.js');
claim('live.js has the block and its one listener', substr_count($live, BLOCK_LISTENER) === 1 && str_contains($live, 'window.taxmodChosen = chosen'));
claim('the shared tree says the value it writes', (bool) preg_match('/feld\.value = radio\.value;\s*(?:\/\/[^\n]*\n\s*)?if \( window\.taxmodChosen \) \{\s*window\.taxmodChosen\( feld \);/', $admin));

$dir = sys_get_temp_dir() . '/taxmod-chosen-' . getmypid();
@mkdir($dir);
copy($self . '/assets/live.js', $dir . '/live.js');
copy($self . '/assets/admin.js', $dir . '/admin.js');

$page = <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><title>chosen</title></head><body><div class="wrap">
<form id="f" method="post">
  <select name="values[12]" id="sel"><option value="">—</option><option value="7">Sieben</option><option value="8">Acht</option></select>
  <input type="checkbox" name="flag" value="1" id="box">
  <input type="text" name="words" id="txt">
  <span class="taxmod-chooser">
    <input type="checkbox" class="taxmod-dialog-switch" id="sw">
    <label class="button taxmod-dialog-open" for="sw" id="open">—</label>
    <span class="taxmod-dialog"><span class="taxmod-dialog-shade"></span><span class="taxmod-dialog-panel">
      <span class="taxmod-dialog-head"><span class="taxmod-dialog-title"><span class="taxmod-chooser-current">—</span></span>
        <label class="taxmod-dialog-close taxmod-dialog-cancel" for="sw">&times;</label></span>
      <span class="taxmod-chooser-row"><label><input type="radio" name="values[30]" value="" checked id="r0"><span class="taxmod-tree-label">—</span></label></span>
      <span class="taxmod-chooser-row"><label><input type="radio" name="values[30]" value="41" id="r1"><span class="taxmod-tree-label">Widerstände</span></label></span>
      <span class="taxmod-chooser-row"><label><input type="radio" name="values[30]" value="42" id="r2"><span class="taxmod-tree-label">Kondensatoren</span></label></span>
      <span class="taxmod-dialog-foot"><label class="button button-primary taxmod-dialog-ok" for="sw" id="ok">OK</label>
        <label class="button taxmod-dialog-cancel" for="sw" id="cancel">Abbrechen</label></span>
    </span></span>
  </span>
  <input type="hidden" name="values[50]" value="" id="hid">
  <select name="values[60][n1][61]" id="fsel" data-taxmod-fields-of="30" data-taxmod-fields-url="http://taxmod.test/ajax?action=taxmod_node_fields"><option value=""></option></select>
  <div id="filter" data-taxmod-follows="30"><span id="filter-text">alt</span></div>
</form>
</div>
<script src="live.js"></script><script src="admin.js"></script>
<script>
window.said = [];
document.addEventListener( 'taxmod:chosen', function ( e ) {
  window.said.push( { field: e.detail.field, value: e.detail.value, at: e.target.id, form: e.detail.form ? e.detail.form.id : null } );
} );
window.loads = 0;
window.addEventListener( 'beforeunload', function () { window.loads++; } );
</script>
</body></html>
HTML;
file_put_contents($dir . '/page.html', $page);

$script = <<<'JS'
const { chromium } = require('playwright');
const fs = require('fs');
const dir = process.argv[2];
(async () => {
  const out = { errors: [] };
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    page.on('pageerror', e => out.errors.push(String(e)));
    const asked = [];
    const fields = [];
    await page.route('http://taxmod.test/**', route => {
      const url = new URL(route.request().url());
      const name = url.pathname.replace(/^\//, '');
      if (name === 'ajax') {
        fields.push(url.searchParams.get('node'));
        return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: [{ id: '5', name: 'Wert' }] }) });
      }
      if (url.searchParams.has('taxmod_chosen')) {
        asked.push({ chosen: url.searchParams.get('taxmod_chosen'), header: route.request().headers()['x-taxmod-live'] || '' });
        const sent = JSON.parse(url.searchParams.get('taxmod_chosen'));
        return route.fulfill({ contentType: 'text/html', body: '<html><body><div class="wrap"><div id="filter" data-taxmod-follows="30"><span id="filter-text">Felder von ' + (sent['values[30]'] || '-') + '</span></div></div></body></html>' });
      }
      return route.fulfill({ contentType: name.endsWith('.js') ? 'application/javascript' : 'text/html', body: fs.readFileSync(dir + '/' + name) });
    });
    await page.goto('http://taxmod.test/page.html');
    const said = () => page.evaluate(() => { const s = window.said; window.said = []; return s; });

    await page.selectOption('#sel', '7');
    out.select = await said();
    await page.check('#box');
    out.checkbox = await said();
    await page.fill('#txt', 'abc');
    await page.press('#txt', 'Tab');
    out.text = await said();

    await page.click('#open');
    await page.check('#r1', { force: true });
    out.inDialog = await said();
    out.fieldsBeforeOk = fields.length;
    await page.click('#ok', { force: true });
    out.ok = await said();
    await page.waitForFunction(() => document.getElementById('filter-text').textContent !== 'alt', null, { timeout: 5000 }).catch(() => {});
    out.filter = await page.textContent('#filter-text');
    out.asked = asked.slice();
    await page.waitForFunction(() => document.getElementById('fsel').options.length > 1, null, { timeout: 5000 }).catch(() => {});
    out.fields = fields.slice();
    out.fsel = await page.evaluate(() => Array.from(document.getElementById('fsel').options).map(o => o.textContent));

    await page.click('#open');
    await page.check('#r2', { force: true });
    await page.click('#cancel', { force: true });
    out.cancel = await said();
    out.afterCancel = await page.evaluate(() => document.querySelector('input[name="values[30]"]:checked').value);

    await page.click('#open');
    await page.click('#ok', { force: true });
    out.okUnchanged = await said();

    await page.evaluate(() => { const h = document.getElementById('hid'); h.value = '99'; window.taxmodChosen(h); });
    out.script = await said();
    out.loads = await page.evaluate(() => window.loads);
  } catch (e) {
    out.error = String(e);
  }
  await browser.close();
  console.log(JSON.stringify(out));
})();
JS;
file_put_contents($dir . '/measure.js', $script);

$raw    = (string) shell_exec('cd ' . escapeshellarg($dir) . ' && node measure.js ' . escapeshellarg($dir) . ' 2>&1');
$answer = json_decode($raw, true);
array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);

claim('the browser measured', is_array($answer) && ! isset($answer['error']), is_array($answer) ? (string) ($answer['error'] ?? '') : 'no answer from node/playwright: ' . substr($raw, 0, 300));

if (is_array($answer) && ! isset($answer['error'])) {
    $one = static fn (array $said, string $field, mixed $value): bool => count($said) === 1 && $said[0]['field'] === $field && $said[0]['value'] === $value && $said[0]['at'] === 'f' && $said[0]['form'] === 'f';

    claim('the page throws nothing', $answer['errors'] === [], implode(' | ', $answer['errors']));
    claim('a select says taxmod:chosen at its form, once, with field and value', $one($answer['select'], 'values[12]', '7'), json_encode($answer['select']));
    claim('a checkbox says it', $one($answer['checkbox'], 'flag', '1'), json_encode($answer['checkbox']));
    claim('typing is no choice', $answer['text'] === [], json_encode($answer['text']));
    claim('a radio in a dialog with «OK» is not said yet', $answer['inDialog'] === [], json_encode($answer['inDialog']));
    claim('… «OK» says it once', $one($answer['ok'], 'values[30]', '41'), json_encode($answer['ok']));
    claim('… the follower is fetched with the choice, named in its header', count($answer['asked']) === 1 && json_decode($answer['asked'][0]['chosen'], true) === ['values[30]' => '41'] && $answer['asked'][0]['header'] === 'filter', json_encode($answer['asked']));
    claim('… and put in place', $answer['filter'] === 'Felder von 41', (string) $answer['filter']);
    claim('D-1550 rule filter: the node\'s fields are not asked for while the dialog is open', $answer['fieldsBeforeOk'] === 0, (string) $answer['fieldsBeforeOk']);
    claim('… on «OK» they are asked for the chosen node and fill the field choice', $answer['fields'] === ['41'] && $answer['fsel'] === ['', 'Wert'], json_encode([$answer['fields'], $answer['fsel']]));
    claim('«Abbrechen» says nothing and puts the choice back', $answer['cancel'] === [] && $answer['afterCancel'] === '41', json_encode([$answer['cancel'], $answer['afterCancel']]));
    claim('«OK» without a new choice says nothing', $answer['okUnchanged'] === [], json_encode($answer['okUnchanged']));
    claim('a value a script writes is said through taxmodChosen', $one($answer['script'], 'values[50]', '99'), json_encode($answer['script']));
    claim('nothing loaded the page', $answer['loads'] === 0, (string) $answer['loads']);
}

// ---- 2. the ratchet ----------------------------------------------------------------------------------------------------

$repos = ['wp-taxonomy-modeler' => $self];

foreach (glob(dirname($self) . '/wp_tax_*', GLOB_ONLYDIR) ?: [] as $repo) {
    $repos[basename($repo)] = $repo;
}

$change = [];
$submit = [];

foreach ($repos as $name => $repo) {
    foreach (glob($repo . '/assets/*.js') ?: [] as $file) {
        $code = str_replace(BLOCK_LISTENER, '', (string) file_get_contents($file));
        $key  = $name . '/assets/' . basename($file);
        $change[$key] = preg_match_all('/addEventListener\(\s*[\'"]change[\'"]|\.on\(\s*[\'"]change[\'"]|\bonchange\s*[:=]/', $code);
        $submit[$key] = preg_match_all('/\.submit\(\s*\)|requestSubmit\(/', $code);
    }
}

foreach (['raw change listeners' => [$change, CHANGE_PENDING], 'forms sent by a script' => [$submit, SUBMIT_PENDING]] as $what => [$counts, $ceilings]) {
    $over = [];

    foreach ($counts as $key => $count) {
        if ($count > ($ceilings[$key] ?? 0)) {
            $over[] = $key . ' ' . $count . ' > ' . ($ceilings[$key] ?? 0);
        }
    }

    $total = array_sum($counts);
    claim($what . ': no file over its ceiling (' . $total . ' of ' . array_sum($ceilings) . ')', $over === [], implode(', ', $over));

    foreach ($ceilings as $key => $ceiling) {
        if (isset($counts[$key]) && $counts[$key] < $ceiling) {
            echo "note {$key}: {$counts[$key]} below its ceiling {$ceiling} — lower the list\n";
        }
    }
}

echo $failed === 0 ? "\nall green\n" : "\n{$failed} failed\n";
exit($failed === 0 ? 0 : 1);
