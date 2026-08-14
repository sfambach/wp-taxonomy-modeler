<?php
require "C:/devel/wordpress/wp-load.php";
use WTT\Attribute;
use WTT\Node_Type;
use WTT\Relation;
$tax = "wtt_fs";

function term($name) {
  global $tax;
  $t = get_terms(["taxonomy"=>$tax,"name"=>$name,"hide_empty"=>false,"number"=>5]);
  return (!is_wp_error($t) && $t) ? $t[0] : null;
}

$size = term("size");
if ($size) {
  echo "size id={$size->term_id} parent={$size->parent} hidden=" . (int)get_term_meta($size->term_id,"_wtt_hidden",true) . " trash=" . (int)get_term_meta($size->term_id,"_wtt_trashed",true) . "\n";
  echo "  preferred=" . Node_Type::get_preferred_render((int)$size->term_id) . "\n";
}
$qty = term("quantity");
if ($qty) {
  $kids = get_terms(["taxonomy"=>$tax,"parent"=>(int)$qty->term_id,"hide_empty"=>false,"number"=>20]);
  echo "quantity children:\n";
  foreach ((array)$kids as $k) {
    echo "  {$k->name} id={$k->term_id} hidden=".(int)get_term_meta($k->term_id,"_wtt_hidden",true)."\n";
  }
}

$passiv = term("Passiv");
$rows = Attribute::effective_list($tax, (int)$passiv->term_id);
foreach ($rows as $r) {
  if (($r["name"]??"") !== "Toleranz") continue;
  echo "Passiv.Toleranz typeId=" . ($r["typeId"]??0) . " typeName=" . ($r["typeName"]??"") . " typeKey=" . ($r["typeKey"]??"") . "\n";
  $tid = (int)($r["typeId"]??0);
  if ($tid) {
    $tt = get_term($tid, $tax);
    echo "  type term=" . ($tt instanceof WP_Term ? $tt->name . " parent=".$tt->parent : "?") . "\n";
  }
}

$unitType = term("Unit type");
if ($unitType) {
  echo "Unit type id={$unitType->term_id} attrs:\n";
  foreach (Attribute::effective_list($tax,(int)$unitType->term_id) as $r) {
    echo "  ".$r["name"]." type=".($r["typeName"]??"?")."\n";
  }
  echo "  preferred=".Node_Type::get_preferred_render((int)$unitType->term_id)."\n";
}

$montage = term("Bauteil Monatge Typen");
$montage2 = get_terms(["taxonomy"=>$tax,"name"=>"Bauteil Montage Typen","hide_empty"=>false,"number"=>5]);
echo "Monatge term=" . ($montage ? $montage->term_id : "no") . " Montage correct=" . (!is_wp_error($montage2)&&$montage2 ? $montage2[0]->term_id : "no") . "\n";

# With prefix attrs
$wp = term("With prefix");
if ($wp) {
  echo "With prefix attrs:\n";
  foreach (Attribute::list_own($tax,(int)$wp->term_id) as $r) {
    echo "  ".$r["name"]." type=".($r["typeName"]??"?")."\n";
  }
}
