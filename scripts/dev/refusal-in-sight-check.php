<?php declare(strict_types=1);

/**
 * D-1533: a refusal is seen where one acted. Stefan 20.11. «beim ersten Mal nicht gespeichert»: the page stayed down at the rows
 * while the refusal stood in the message field up beside the heading. With the field out of sight, a refusal shows as a toast as
 * well; in sight, it does not; an «ok» never does. Needs node with playwright, no WordPress.
 *
 *     php scripts/dev/refusal-in-sight-check.php
 */

$bad   = 0;
$check = static function (string $what, bool $passed) use (&$bad): void {
    echo ($passed ? '  OK   ' : '  FAIL ') . $what . "\n";
    $bad += $passed ? 0 : 1;
};

$dir = sys_get_temp_dir() . '/taxmod-refusal-' . getmypid();
@mkdir($dir);
$script = dirname(__DIR__, 2) . '/assets/messages.js';
file_put_contents($dir . '/page.html', '<!doctype html><html><head><meta charset="utf-8"></head><body><div class="wrap">'
    . '<span class="taxmod-messages"><button type="button" class="taxmod-messages-last"></button><ol class="taxmod-messages-list" hidden></ol></span>'
    . '<div style="height:3000px"></div><p id="rows">rows</p></div><script src="file://' . $script . '"></script></body></html>');
file_put_contents($dir . '/measure.js', <<<'JS'
const { chromium } = require('playwright');
(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1200, height: 700 } });
  await page.goto('file://' + process.argv[2] + '/page.html');
  const toasts = () => page.evaluate(() => document.querySelectorAll('.taxmod-toast').length);
  const out = {};
  await page.evaluate(() => window.taxmodMessage('in sight', 'bad'));
  out.inSight = await toasts();
  await page.evaluate(() => document.getElementById('rows').scrollIntoView());
  await page.evaluate(() => window.taxmodMessage('fine', 'ok'));
  out.okAway = await toasts();
  await page.evaluate(() => window.taxmodMessage('refused', 'bad'));
  out.badAway = await toasts();
  out.field = await page.evaluate(() => document.querySelector('.taxmod-messages-last').textContent);
  console.log(JSON.stringify(out));
  await browser.close();
})().catch(e => console.log(JSON.stringify({ error: String(e) })));
JS);
$answer = json_decode((string) shell_exec('node ' . escapeshellarg($dir . '/measure.js') . ' ' . escapeshellarg($dir) . ' 2>&1'), true);
array_map('unlink', glob($dir . '/*') ?: []);
@rmdir($dir);

$check('the browser measured', is_array($answer) && ! isset($answer['error']));
$check('a refusal with the field in sight stays in the field only', ($answer['inSight'] ?? -1) === 0);
$check('an «ok» out of sight stays in the field only', ($answer['okAway'] ?? -1) === 0);
$check('a refusal out of sight shows as a toast as well', ($answer['badAway'] ?? 0) === 1);
$check('and it stands in the field', ($answer['field'] ?? '') === 'refused');

echo $bad === 0 ? "refusal-in-sight-check: all green\n" : "refusal-in-sight-check: $bad FAIL\n";
exit($bad === 0 ? 0 : 1);
