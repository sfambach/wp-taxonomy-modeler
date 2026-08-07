<?php
/**
 * Refresh Bauteile catalog merge on Fallstudie (wtt_fs).
 *
 * @package WP_Taxonomy_Tree
 */

$tax = 'wtt_fs';
WTT\Case_Data::ensure_bauteile_catalog( $tax );

$bau   = WTT\Demo_Data::find_term_by_path( $tax, array( 'Fallstudie', 'Implementation', 'Bauteile' ) );
$arten = WTT\Demo_Data::find_term_by_path( $tax, array( 'Fallstudie', 'Definition', 'Bauteilarten' ) );
echo "$tax Bauteile=$bau Bauteilarten(legacy)=$arten\n";
