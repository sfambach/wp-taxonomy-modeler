<?php
/**
 * Assert Lieferant is not a Bauteil kind slot (Q83 merge).
 *
 * @package WP_Taxonomy_Tree
 */

$tax  = WTT\Taxonomy::FS;
$slot = WTT\Demo_Data::find_term_by_path(
	$tax,
	array( 'Fallstudie', 'Implementation', 'Bauteile', 'Widerstand', 'Lieferant' )
);
$legacy = WTT\Demo_Data::find_term_by_path(
	$tax,
	array( 'Fallstudie', 'Definition', 'Bauteilarten', 'Widerstand', 'Lieferant' )
);
echo 'Widerstand/Lieferant slot=' . $slot . ' legacy=' . $legacy . PHP_EOL;
if ( $slot > 0 || $legacy > 0 ) {
	fwrite( STDERR, "Lieferant slot should be removed from Bauteil kinds\n" );
	exit( 1 );
}
echo "OK no Lieferant on Bauteil kinds\n";
