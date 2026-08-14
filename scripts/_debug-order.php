<?php
require "C:/Devel/Wordpress/wp-load.php";
$tax="wtt_fs";
foreach(["With prefix","Without prefix","Basiseinheiten","Gramm","Meter"] as $name){
  $t=get_terms(["taxonomy"=>$tax,"hide_empty"=>false,"name"=>$name]);
  if(empty($t)||is_wp_error($t)){echo "$name missing\n";continue;}
  $id=(int)$t[0]->term_id;
  echo "== $name $id ==\n";
  foreach(WTT\Attribute::list($tax,$id) as $i=>$a){
    echo "  $i ".($a["name"]??"?")." inh=".(!empty($a["inherited"])?"Y":"N")." on=".($a["definedOnName"]??"")."\n";
  }
}
