<?php declare(strict_types=1);

/**
 * A dialog whose action is risky says «Zurück» first (ListPage::dialogAction with `back`): «Zurück» is the primary button and
 * holds the focus when the dialog opens, it closes the dialog without sending, and the action is a plain button with its own
 * word. A dialog without `back` stays as it was: primary action, «Abbrechen» beside it.
 *
 * ⚠️ *Prüftor 21.11., live check C1 (Fertigung): two buttons both said «Abbrechen», and the one that cancelled the order held
 * the focus — one Enter after opening and the order was gone.*
 *
 *     php scripts/dev/dialog-back-check.php
 *
 * Needs no WordPress (the few escaping functions are stood in for); needs node with playwright.
 *
 * @see docs/NewConcept/90-decision-log.md D-804, D-1063
 */

foreach (['esc_attr', 'esc_html', 'esc_url', 'esc_html__', 'esc_attr__'] as $stub) {
    if (! function_exists($stub)) {
        eval('function ' . $stub . '($text, $domain = "") { return htmlspecialchars((string) $text, ENT_QUOTES); }');
    }
}

if (! function_exists('__')) {
    function __(string $text, string $domain = ''): string
    {
        return $text === 'Cancel' ? 'Abbrechen' : $text;
    }
}

$self = dirname(__DIR__, 2);
require $self . '/vendor/autoload.php';

use Taxmod\WordPress\Admin\ListPage;

$failed = 0;

function claim(string $what, bool $holds, string $detail = ''): void
{
    global $failed;
    echo ($holds ? 'ok   ' : 'FAIL ') . $what . ($holds || $detail === '' ? '' : ' — ' . $detail) . "\n";
    $failed += $holds ? 0 : 1;
}

$risky = ListPage::dialogAction('risky', 'Abbrechen', 'Abbrechen: Auftrag 1', '<p>Nichts wird gelöscht.</p>', 'do', 'cancel', 'Auftrag abbrechen', back: 'Zurück');
$plain = ListPage::dialogAction('plain', 'Umbuchen', 'Umbuchen: Posten 1', '<p>Wohin?</p>', 'do', 'move', 'Umbuchen');

claim('risky: no button says «Abbrechen»', ! preg_match('/<(?:button|label) class="button[^"]*"[^>]*>Abbrechen<\/(?:button|label)>/', substr($risky, (int) strpos($risky, 'taxmod-dialog-foot'))));
claim('plain: «Abbrechen» stands beside the primary action', str_contains($plain, 'taxmod-dialog-cancel" for="plain">Abbrechen</label>') && str_contains($plain, 'class="button button-primary" name="do" value="move"'));

$dir = sys_get_temp_dir() . '/taxmod-dialog-back-' . getmypid();
@mkdir($dir);
copy($self . '/assets/live.js', $dir . '/live.js');
copy($self . '/assets/admin.js', $dir . '/admin.js');
file_put_contents($dir . '/page.html', '<!doctype html><html><head><meta charset="utf-8"><title>back</title></head><body><div class="wrap">'
    . '<form id="f" method="post" action="http://taxmod.test/sent">' . $risky . $plain . '</form></div>'
    . '<script src="live.js"></script><script src="admin.js"></script></body></html>');

file_put_contents($dir . '/measure.js', <<<'JS'
const { chromium } = require('playwright');
const fs = require('fs');
const dir = process.argv[2];
(async () => {
  const out = { errors: [] };
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    page.on('pageerror', e => out.errors.push(String(e)));
    let sent = 0;
    await page.route('http://taxmod.test/**', route => {
      const name = new URL(route.request().url()).pathname.replace(/^\//, '');
      if (name === 'sent') { sent++; return route.fulfill({ contentType: 'text/html', body: 'sent' }); }
      return route.fulfill({ contentType: name.endsWith('.js') ? 'application/javascript' : 'text/html', body: fs.readFileSync(dir + '/' + name) });
    });
    await page.goto('http://taxmod.test/page.html');
    await page.click('label[for="risky"].taxmod-dialog-open');
    await page.waitForTimeout(50);
    out.focus = await page.evaluate(() => { const a = document.activeElement; return a ? a.textContent + '|' + a.className : ''; });
    out.primary = await page.evaluate(() => Array.from(document.querySelectorAll('#risky ~ .taxmod-dialog .button-primary')).map(b => b.textContent));
    await page.click('#risky ~ .taxmod-dialog .taxmod-dialog-back');
    await page.waitForTimeout(50);
    out.openAfterBack = await page.evaluate(() => document.getElementById('risky').checked);
    out.sent = sent;
  } catch (e) {
    out.error = String(e);
  }
  await browser.close();
  console.log(JSON.stringify(out));
})();
JS);

$raw    = (string) shell_exec('cd ' . escapeshellarg($dir) . ' && node measure.js ' . escapeshellarg($dir) . ' 2>&1');
$answer = json_decode($raw, true);
array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);

claim('the browser measured', is_array($answer) && ! isset($answer['error']), is_array($answer) ? (string) ($answer['error'] ?? '') : 'no answer from node/playwright: ' . substr($raw, 0, 300));

if (is_array($answer) && ! isset($answer['error'])) {
    claim('the page throws nothing', $answer['errors'] === [], implode(' | ', $answer['errors']));
    claim('risky: on opening «Zurück» holds the focus', str_starts_with((string) $answer['focus'], 'Zurück|'), (string) $answer['focus']);
    claim('risky: «Zurück» is the only primary button', $answer['primary'] === ['Zurück'], json_encode($answer['primary']));
    claim('risky: «Zurück» closes the dialog', $answer['openAfterBack'] === false);
    claim('risky: … and sends nothing', $answer['sent'] === 0, (string) $answer['sent']);
}

echo $failed === 0 ? "\nall green\n" : "\n{$failed} failed\n";
exit($failed === 0 ? 0 : 1);
