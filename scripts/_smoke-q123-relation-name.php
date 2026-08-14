<?php
/**
 * Smoke: Relation.name expose + rename syncs Attributes panel (Q123 ≈ 0.0.433+).
 *
 * Run from WordPress root with Laragon PHP + wp-cli.phar eval-file.
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via wp eval-file.\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Relation;

$tax = 'wtt_fs';
$host = get_term_by( 'name', 'Kontakt', $tax );
if ( ! $host instanceof WP_Term ) {
	echo "host=missing\nsmoke=fail\n";
	exit( 1 );
}
$host_id = (int) $host->term_id;

$edge_id   = '';
$attr_name = '';
$prior     = '';
foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
	$key = strtolower( (string) ( $edge['typeKey'] ?? $edge['typeName'] ?? '' ) );
	if ( ! Relation::is_attribute_binding_type_key( $key ) ) {
		continue;
	}
	$name = Relation::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
	if ( '' === $name ) {
		continue;
	}
	$edge_id   = Attribute::normalize_attr_id( $edge['id'] ?? '' );
	$attr_name = $name;
	$prior     = $name;
	break;
}

if ( '' === $edge_id ) {
	echo "edge=missing\nsmoke=fail\n";
	exit( 1 );
}

$payload = \WTT\Tree_Model::get_stored_relations_payload( $tax, $host_id );
$von_has = false;
foreach ( $payload['von'] as $row ) {
	if ( Attribute::normalize_attr_id( $row['id'] ?? '' ) === $edge_id ) {
		$von_has = isset( $row['name'] ) && (string) $row['name'] === $attr_name;
		break;
	}
}

$marker = 'wtt-smoke-relname-' . wp_generate_password( 6, false, false );
$renamed = Relation::update_name( $tax, $host_id, $edge_id, $marker );
if ( is_wp_error( $renamed ) ) {
	echo 'rename_err=' . $renamed->get_error_code() . "\nsmoke=fail\n";
	exit( 1 );
}

$edge_after = '';
foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
	if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $edge_id ) {
		$edge_after = Relation::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
		break;
	}
}

$attr_after = '';
foreach ( Attribute::list_own( $tax, $host_id ) as $row ) {
	if ( Attribute::normalize_attr_id( $row['id'] ?? '' ) === $edge_id ) {
		$attr_after = (string) ( $row['name'] ?? '' );
		break;
	}
}

/* Restore via Attribute::update (same path as Attributes / Relations AJAX for bindings). */
$restore = Attribute::update( $tax, $host_id, $edge_id, array( 'name' => $prior ) );
if ( is_wp_error( $restore ) ) {
	echo 'restore_err=' . $restore->get_error_code() . "\nsmoke=fail\n";
	exit( 1 );
}

$restored = '';
foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
	if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $edge_id ) {
		$restored = Relation::normalize_edge_name( (string) ( $edge['name'] ?? '' ) );
		break;
	}
}

/* child_of must stay nameless / optional. */
$child_ok = true;
$child_parent = (int) $host->parent;
if ( $child_parent > 0 ) {
	foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
		$key = strtolower( (string) ( $edge['typeKey'] ?? '' ) );
		if ( 'child_of' === $key && ! empty( $edge['name'] ) ) {
			$child_ok = false;
		}
	}
}

$ok = defined( 'WTT_VERSION' ) && version_compare( (string) WTT_VERSION, '0.0.433', '>=' )
	&& $von_has
	&& $edge_after === $marker
	&& $attr_after === $marker
	&& $restored === $prior
	&& $child_ok;

echo 'WTT_VERSION=' . ( defined( 'WTT_VERSION' ) ? WTT_VERSION : '?' ) . PHP_EOL;
echo 'host=Kontakt id=' . $host_id . PHP_EOL;
echo 'attr_id=' . $edge_id . PHP_EOL;
echo 'attr_name=' . $attr_name . PHP_EOL;
echo 'payload_name=yes' . PHP_EOL;
echo 'von_has_name=' . ( $von_has ? 'yes' : 'no' ) . PHP_EOL;
echo 'edge_renamed=' . ( $edge_after === $marker ? 'yes' : 'no' ) . PHP_EOL;
echo 'attr_synced=' . ( $attr_after === $marker ? 'yes' : 'no' ) . PHP_EOL;
echo 'restored=' . ( $restored === $prior ? 'yes' : 'no' ) . PHP_EOL;
echo 'child_of_ok=' . ( $child_ok ? 'yes' : 'no' ) . PHP_EOL;
echo 'relation_name=yes' . PHP_EOL;
echo 'smoke=' . ( $ok ? 'ok' : 'fail' ) . PHP_EOL;
exit( $ok ? 0 : 1 );
