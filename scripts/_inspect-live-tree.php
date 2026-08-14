<?php
require "C:/devel/wordpress/wp-load.php";
if (!class_exists("WTT\\Tree_Model")) { echo "plugin not loaded\n"; exit(1); }
use WTT\Tree_Model;
use WTT\Attribute;
use WTT\Node_Type;

$tax = "wtt_fs";
$tree = Tree_Model::get_tree($tax);

function walk($nodes, $depth = 0, &$lines = []) {
  foreach ($nodes as $n) {
    if (!empty($n["isTrash"]) || !empty($n["isHiddenBin"])) continue;
    $indent = str_repeat("  ", $depth);
    $tid = (int)($n["typeId"] ?? 0);
    $tn = (string)($n["typeName"] ?? "");
    $pr = (string)($n["preferredRender"] ?? "");
    $pc = (string)($n["preferredConverter"] ?? "");
    $tpl = !empty($n["isTemplate"]) ? "T" : "-";
    $lines[] = sprintf("%s%s [%d] type=%s(%d) pref=%s conv=%s tpl=%s", $indent, $n["name"], (int)$n["id"], $tn, $tid, $pr ?: "-", $pc ?: "-", $tpl);
    if (!empty($n["children"]) && is_array($n["children"])) {
      walk($n["children"], $depth + 1, $lines);
    }
  }
  return $lines;
}
$lines = walk($tree);
echo implode("\n", $lines) . "\n";
echo "NODES=" . count($lines) . "\n";

# Sample hosts with attributes
$hosts = ["Percent","Toleranz","Passiv","Widerstand","Bauformen","size","quantity","Meter"];
foreach ($hosts as $name) {
  $terms = get_terms(["taxonomy"=>$tax,"name"=>$name,"hide_empty"=>false,"number"=>5]);
  if (is_wp_error($terms) || empty($terms)) { echo "HOST $name MISSING\n"; continue; }
  $t = $terms[0];
  $id = (int)$t->term_id;
  $eff = Attribute::effective_list($tax, $id);
  $own = Attribute::list_own($tax, $id);
  echo "\n=== $name id=$id parent={$t->parent} pref=" . Node_Type::get_preferred_render($id) . " ownPref=" . (Node_Type::has_own_preferred_render($id)?"yes":"no") . " ===\n";
  echo "attrs_effective=" . count($eff) . " own=" . count($own) . "\n";
  foreach ($eff as $r) {
    echo sprintf("  - %s id=%s type=%s inh=%s mult=%s ro=%s hide=%s\n",
      $r["name"] ?? "?",
      $r["id"] ?? "?",
      $r["typeName"] ?? "?",
      !empty($r["inherited"]) ? "yes" : "no",
      $r["multiplicity"] ?? "?",
      !empty($r["readonly"]) ? "1" : "0",
      !empty($r["hidden"]) ? "1" : "0"
    );
  }
}
