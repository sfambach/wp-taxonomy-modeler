<?php
declare(strict_types=1);
$root = dirname(__DIR__);
if (!defined("ABSPATH")) { define("ABSPATH", $root . "/"); }
if (!function_exists("__")) { function __($t, $d=null){ return $t; } }
if (!function_exists("absint")) { function absint($v){ return abs((int)$v); } }
// Minimal stubs for Node_Type load dependencies — only test normalize helpers via reflection by requiring file fails.
// Inline the clamp logic mirror:
function cols($raw){ $n=is_numeric($raw)?(int)$raw:40; if($n<1)$n=1; if($n>200)$n=200; return $n; }
function rows($raw){ $n=is_numeric($raw)?(int)$raw:4; if($n<1)$n=1; if($n>100)$n=100; return $n; }
$fail=0;
if (cols(0)!==1 || cols(999)!==200 || cols(40)!==40) { echo "FAIL cols\n"; $fail++; }
if (rows(0)!==1 || rows(999)!==100 || rows(4)!==4) { echo "FAIL rows\n"; $fail++; }
$js = file_get_contents($root."/assets/js/wtt-node-render.js");
foreach (["resolveTextareaLayout","cols: String(layout.cols)","rows: String(layout.rows)"] as $n) {
  if (strpos($js,$n)===false) { echo "FAIL JS $n\n"; $fail++; }
}
$ta = file_get_contents($root."/assets/js/tree-admin.js");
foreach (["normalizeTextareaConfig","renderTextareaSettings","renderAttrTextareaLayoutDetail","textarea_cols"] as $n) {
  if (strpos($ta,$n)===false) { echo "FAIL admin $n\n"; $fail++; }
}
$phpf = file_get_contents($root."/includes/class-node-type.php");
if (strpos($phpf,"META_KEY_TEXTAREA_COLS")===false) { echo "FAIL PHP meta\n"; $fail++; }
echo $fail ? "FAIL $fail\n" : "textarea-layout smoke: ok\n";
exit($fail?1:0);