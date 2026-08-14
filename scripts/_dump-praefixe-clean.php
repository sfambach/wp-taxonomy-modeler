<?php
declare(strict_types=1);
require 'C:/devel/wordpress/wp-load.php';

use WTT\Attribute;
use WTT\Case_Data;
use WTT\Node_Presentation;
use WTT\Node_Type;
use WTT\Taxonomy;
use WTT\Tree_Model;

$tax = Taxonomy::FS;
$id  = Case_Data::find_catalog_folder( $tax, 'prefixes' );
$kids = get_terms(
	array(
		'taxonomy'   => $tax,
		'parent'     => $id,
		'hide_empty' => false,
		'number'     => 0,
		'orderby'    => 'meta_value_num',
		'order'      => 'ASC',
		'meta_key'   => '_wtt_multiplikator',
	)
);
if ( ! is_array( $kids ) || empty( $kids ) ) {
	$kids = get_terms(
		array(
			'taxonomy'   => $tax,
			'parent'     => $id,
			'hide_empty' => false,
			'number'     => 0,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);
}

$rows = array();
foreach ( (array) $kids as $c ) {
	if ( ! $c instanceof WP_Term ) {
		continue;
	}
	$cid = (int) $c->term_id;
	$m   = Node_Type::get_multiplikator( $cid );
	$rows[] = array(
		'name'          => $c->name,
		'id'            => $cid,
		'multiplikator' => $m,
		'short'         => Tree_Model::get_short_description( $cid ),
		'symbol'        => Node_Presentation::get( $cid, 'symbol' ),
		'table'         => Node_Presentation::get( $cid, 'table' ),
		'form'          => Node_Presentation::get( $cid, 'form' ),
		'select'        => Node_Presentation::get( $cid, 'select' ),
		'help'          => Node_Presentation::get( $cid, 'help' ),
		'pref'          => Node_Type::get_preferred_render( $cid ),
		'desc'          => $c->description,
	);
}
usort(
	$rows,
	static function ( $a, $b ) {
		$am = $a['multiplikator'] ?? 0;
		$bm = $b['multiplikator'] ?? 0;
		if ( $am == $bm ) {
			return strcmp( (string) $a['name'], (string) $b['name'] );
		}
		return $am <=> $bm;
	}
);

$payload = array(
	'hostPreferred' => Node_Type::get_preferred_render( $id ),
	'choiceFilter'  => Node_Type::get_choice_filter( $id ),
	'attrs'         => array(),
	'children'      => $rows,
);
foreach ( Attribute::list_own( $tax, $id ) as $r ) {
	$payload['attrs'][] = array(
		'name'     => $r['name'] ?? '',
		'typeKey'  => $r['typeKey'] ?? '',
		'mult'     => $r['multiplicity'] ?? '',
		'binding'  => $r['binding'] ?? '',
		'ro'       => ! empty( $r['readonly'] ),
		'hide'     => ! empty( $r['hidden'] ),
		'ctx'      => $r['presentationConfig']['context'] ?? '',
		'extras'   => $r['typeExtras'] ?? null,
		'settings' => $r['settings'] ?? null,
	);
}

file_put_contents(
	__DIR__ . '/_dump-praefixe-clean.json',
	wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
);
echo "wrote " . __DIR__ . "/_dump-praefixe-clean.json\n";
echo 'children=' . count( $rows ) . "\n";
foreach ( $rows as $r ) {
	echo $r['name'] . "\t" . $r['multiplikator'] . "\t" . $r['symbol'] . "\t" . $r['pref'] . "\n";
}
