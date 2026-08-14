<?php
/**
 * One-shot Laragon smoke: preferredConverter write → edge Settings only (no host map).
 *
 * Usage (from WordPress docroot):
 *   php wp-cli.phar eval-file C:\Devel\Wordpress\source\wp-taxonomy-tree\scripts\_smoke-q123-type-extras-edge.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Attribute_Q123_Migrate;
use WTT\Relation;
use WTT\Settings_Walk;
use WTT\Taxonomy;

$tax = Taxonomy::FS;

/* Ensure fold + prune flags run. */
Attribute_Q123_Migrate::maybe_migrate( $tax );

$hosts  = array( 'Kontakt', 'Preis', 'Widerstand', 'Wert' );
$host   = null;
$attrs  = array();
$target = null;
foreach ( $hosts as $name ) {
	$term = get_term_by( 'name', $name, $tax );
	if ( ! $term instanceof WP_Term ) {
		continue;
	}
	$own = Attribute::list_own( $tax, (int) $term->term_id );
	$pick = null;
	foreach ( $own as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$tk = (string) ( $row['typeKey'] ?? '' );
		if ( 'int' === $tk || 'integer' === $tk ) {
			$pick = $row;
			break;
		}
	}
	if ( null === $pick ) {
		foreach ( $own as $row ) {
			if ( is_array( $row ) && '' !== Attribute::normalize_attr_id( $row['id'] ?? '' ) ) {
				$pick = $row;
				break;
			}
		}
	}
	if ( null !== $pick ) {
		$host   = $term;
		$attrs  = $own;
		$target = $pick;
		break;
	}
}
if ( ! $host instanceof WP_Term || null === $target ) {
	fwrite( STDERR, "No own attributes on smoke hosts (" . implode( ', ', $hosts ) . ")\n" );
	exit( 1 );
}

$host_id = (int) $host->term_id;
unset( $attrs );

$attr_id = Attribute::normalize_attr_id( $target['id'] ?? '' );
$marker  = 'roman';

$map_before = get_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS, true );
if ( ! is_array( $map_before ) ) {
	$map_before = array();
}
$host_had_key = isset( $map_before[ $attr_id ] );

$result = Attribute::set_type_extras(
	$tax,
	$host_id,
	$attr_id,
	array( 'preferredConverter' => $marker )
);
if ( is_wp_error( $result ) ) {
	fwrite( STDERR, 'set_type_extras failed: ' . $result->get_error_message() . "\n" );
	exit( 1 );
}

$map_after = get_term_meta( $host_id, Attribute::META_KEY_TYPE_EXTRAS, true );
if ( ! is_array( $map_after ) ) {
	$map_after = array();
}
$host_has_key = isset( $map_after[ $attr_id ] );

$edge = null;
foreach ( Attribute::BINDINGS as $binding_key ) {
	foreach ( Relation::list_outgoing_by_type_key( $tax, $host_id, $binding_key ) as $candidate ) {
		if ( Attribute::normalize_attr_id( $candidate['id'] ?? '' ) === $attr_id ) {
			$edge = $candidate;
			break 2;
		}
	}
}
$edge_settings = ( is_array( $edge ) && isset( $edge['settings'] ) && is_array( $edge['settings'] ) )
	? $edge['settings']
	: null;
$from_edge     = Settings_Walk::type_extras_from_deltas( $edge_settings );
$edge_conv     = isset( $from_edge['preferredConverter'] ) ? (string) $from_edge['preferredConverter'] : '';

$decorated = null;
foreach ( Attribute::list( $tax, $host_id ) as $row ) {
	if ( Attribute::normalize_attr_id( $row['id'] ?? '' ) === $attr_id ) {
		$decorated = $row;
		break;
	}
}
$read_conv = '';
if ( is_array( $decorated ) ) {
	if ( isset( $decorated['preferredConverter'] ) ) {
		$read_conv = (string) $decorated['preferredConverter'];
	} elseif ( isset( $decorated['typeExtras']['preferredConverter'] ) ) {
		$read_conv = (string) $decorated['typeExtras']['preferredConverter'];
	}
}

$prune_flags = get_option( Attribute_Q123_Migrate::OPTION_TYPE_EXTRAS_PRUNED, array() );
$pruned      = is_array( $prune_flags ) && ! empty( $prune_flags[ $tax ] );

echo 'WTT_VERSION=' . ( defined( 'WTT_VERSION' ) ? WTT_VERSION : '?' ) . PHP_EOL;
echo 'host=' . $host->name . ' id=' . $host_id . PHP_EOL;
echo 'attr_id=' . $attr_id . PHP_EOL;
echo 'attr_name=' . (string) ( $target['name'] ?? '' ) . PHP_EOL;
echo 'typeKey=' . (string) ( $target['typeKey'] ?? '' ) . PHP_EOL;
echo 'host_had_key_before=' . ( $host_had_key ? 'yes' : 'no' ) . PHP_EOL;
echo 'host_has_key_after=' . ( $host_has_key ? 'yes' : 'no' ) . PHP_EOL;
echo 'edge_preferredConverter=' . $edge_conv . PHP_EOL;
echo 'decorate_preferredConverter=' . $read_conv . PHP_EOL;
echo 'prune_flag=' . ( $pruned ? 'yes' : 'no' ) . PHP_EOL;

$ok = ( ! $host_has_key ) && ( $marker === $edge_conv );
echo 'edge_only_write=' . ( $ok ? 'yes' : 'no' ) . PHP_EOL;

if ( ! $ok ) {
	fwrite( STDERR, "FAIL: expected edge-only preferredConverter write\n" );
	exit( 1 );
}

echo "smoke=ok\n";
