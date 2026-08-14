<?php
require "C:/devel/wordpress/wp-load.php";
use WTT\Attribute;
use WTT\Taxonomy;
$tax = Taxonomy::FS;
$g = get_term_by("name","Gramm",$tax);
foreach (Attribute::list($tax,(int)$g->term_id) as $r) {
  if (stripos($r["name"]??"","Kuerzel")===false && stripos($r["name"]??"","Praef")===false) continue;
  echo ($r["name"]??"?") . "\n";
  echo "  presentationConfig=" . wp_json_encode($r["presentationConfig"]??null) . "\n";
  echo "  typeExtras=" . wp_json_encode($r["typeExtras"]??null) . "\n";
  echo "  settings=" . wp_json_encode($r["settings"]??null, JSON_UNESCAPED_UNICODE) . "\n";
}
