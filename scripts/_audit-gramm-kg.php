<?php
require "C:/devel/wordpress/wp-load.php";
use WTT\Attribute;
use WTT\Node_Presentation;
use WTT\Node_Type;
use WTT\Taxonomy;
use WTT\Tree_Model;
$tax = Taxonomy::FS;
$g = get_term_by("name","Gramm",$tax);
if (!$g) { echo "no Gramm\n"; exit; }
$id = (int)$g->term_id;
echo "Gramm id=$id\n";
echo "shortDescription=" . var_export(Tree_Model::get_short_description($id), true) . "\n";
echo "presentation=" . wp_json_encode(Node_Presentation::map_for_term_resolved($tax,$id), JSON_UNESCAPED_UNICODE) . "\n";
echo "preferred=" . var_export(Node_Type::get_preferred_render($id), true) . "\n";
echo "prefix_root_to_si=" . Node_Type::get_prefix_root_to_si($id) . "\n";
foreach (Attribute::list($tax,$id) as $r) {
  echo "ATTR " . ($r["name"]??"?")
    . " type=" . ($r["typeName"]??"?")
    . " mult=" . ($r["multiplicity"]??"?")
    . " inherited=" . (!empty($r["inherited"])?"yes":"no")
    . " default=" . wp_json_encode($r["fixedValues"]??null)
    . " label=" . ($r["fixedLabel"]??"")
    . "\n";
}
$schema = Node_Type::get_quantity_schema_for_type($tax,$id);
foreach (($schema["members"]??[]) as $m) {
  echo "SCHEMA " . ($m["name"]??"?")
    . " fixedLiteral=" . ($m["fixedLiteral"]??"")
    . " sample=" . ($m["sample"]??"")
    . " short=" . ($m["shortDescription"]??"")
    . "\n";
}
# meta dump relevant
foreach (["_wtt_short_description","_wtt_fixed_literal","_wtt_fixed_enabled","_wtt_preferred_render"] as $k) {
  echo "meta $k=" . var_export(get_term_meta($id,$k,true), true) . "\n";
}
