<?php
/**
 * Inspect Währung / currency duplication and visible tree vs trash.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

require_once 'C:/devel/wordpress/wp-load.php';

$names = array( 'Euro', 'US Dollar', 'Pound', 'Meter', 'Ohm', 'Stück', 'Währung', 'Basiseinheiten', 'Unit', 'With prefix' );

foreach ( $names as $name ) {
	echo "=== $name ===\n";
	$terms = get_terms(
		array(
			'taxonomy'   => 'wtt_fs',
			'name'       => $name,
			'hide_empty' => false,
			'number'     => 0,
		)
	);
	if ( ! is_array( $terms ) || empty( $terms ) ) {
		echo "  (none)\n";
		continue;
	}
	foreach ( $terms as $t ) {
		$p     = get_term( (int) $t->parent, 'wtt_fs' );
		$pn    = $p instanceof WP_Term ? $p->name : '?';
		$trash = \WTT\Trash::is_trashed( (int) $t->term_id ) ? 'TRASH' : 'live';
		$path  = \WTT\Node_Type::class; // silence
		echo "  id={$t->term_id} parent={$pn}({$t->parent}) slug={$t->slug} $trash\n";
	}
}

echo "=== Währung children ===\n";
$w = 5120;
$kids = get_terms(
	array(
		'taxonomy'   => 'wtt_fs',
		'parent'     => $w,
		'hide_empty' => false,
		'number'     => 0,
	)
);
if ( is_array( $kids ) ) {
	foreach ( $kids as $t ) {
		$trash = \WTT\Trash::is_trashed( (int) $t->term_id ) ? 'TRASH' : 'live';
		echo "  {$t->term_id} {$t->name} $trash\n";
	}
}

echo "=== get_tree sample under Data Types/Unit ===\n";
$tree = \WTT\Tree_Model::get_tree( 'wtt_fs' );
/* Find Unit node recursively and print. */
$walk = null;
$walk = static function ( array $nodes, string $indent = '' ) use ( &$walk ): void {
	foreach ( $nodes as $n ) {
		$name = (string) ( $n['name'] ?? '' );
		$id   = (int) ( $n['id'] ?? 0 );
		echo $indent . $id . ' ' . $name . PHP_EOL;
		if ( ! empty( $n['children'] ) && is_array( $n['children'] ) ) {
			/* Only dive into Definition/Data Types/Unit area for brevity. */
			if ( in_array( $name, array( 'Fallstudie', 'Definition', 'Data Types', 'Unit', 'With prefix', 'Without prefix', 'Währung', 'Präfixe' ), true )
				|| $indent === ''
			) {
				$walk( $n['children'], $indent . '  ' );
			} elseif ( in_array( $name, array( 'With prefix', 'Without prefix' ), true ) ) {
				$walk( $n['children'], $indent . '  ' );
			}
		}
	}
};

/* Better: find Data Types and dump Unit fully. */
$find = null;
$find = static function ( array $nodes, string $want ) use ( &$find ): ?array {
	foreach ( $nodes as $n ) {
		if ( ( $n['name'] ?? '' ) === $want ) {
			return $n;
		}
		if ( ! empty( $n['children'] ) ) {
			$hit = $find( $n['children'], $want );
			if ( null !== $hit ) {
				return $hit;
			}
		}
	}
	return null;
};

$dt = $find( $tree, 'Data Types' );
if ( null === $dt ) {
	echo "Data Types not in get_tree\n";
	exit( 0 );
}
echo "Data Types children in get_tree:\n";
foreach ( $dt['children'] ?? array() as $c ) {
	echo '  ' . $c['id'] . ' ' . $c['name'] . PHP_EOL;
}
$unit = $find( array( $dt ), 'Unit' );
if ( null === $unit ) {
	$unit = $find( $dt['children'] ?? array(), 'Unit' );
}
if ( null !== $unit ) {
	$dump = null;
	$dump = static function ( array $n, string $indent = '' ) use ( &$dump ): void {
		echo $indent . $n['id'] . ' ' . $n['name'] . PHP_EOL;
		foreach ( $n['children'] ?? array() as $c ) {
			$dump( $c, $indent . '  ' );
		}
	};
	echo "Unit subtree from get_tree:\n";
	$dump( $unit );
}
