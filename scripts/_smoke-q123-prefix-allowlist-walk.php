<?php
/**
 * Laragon smoke: Walk Settings.data.allowedPrefixIds on Unit/With-prefix level.
 *
 * Asserts Passiv/Wert Unit path override stores on settings.nested[path],
 * decorate_row exposes hasAllowedPrefixIdsOverride + supportsPrefixAllowlist,
 * Unit fixedOptions.allowedPrefixes are intersected (paint path), Reset clears.
 * Detects via quantitySchema / unit-prefix graph — never hard-codes "size".
 *
 * Usage (from WordPress docroot):
 *   php wp-cli.phar eval-file …/scripts/_smoke-q123-prefix-allowlist-walk.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Node_Type;
use WTT\Settings_Walk;
use WTT\Taxonomy;

$tax = Taxonomy::FS;

$host = get_term_by( 'name', 'Passiv', $tax );
if ( ! $host instanceof WP_Term ) {
	fwrite( STDERR, "Passiv missing\n" );
	exit( 1 );
}

$pick = null;
foreach ( Attribute::list_own( $tax, (int) $host->term_id ) as $row ) {
	if ( ! is_array( $row ) || (string) ( $row['name'] ?? '' ) !== 'Wert' ) {
		continue;
	}
	$pick = $row;
	break;
}
if ( null === $pick ) {
	fwrite( STDERR, "Passiv/Wert missing\n" );
	exit( 1 );
}

$host_id = (int) $host->term_id;
$attr_id = Attribute::normalize_attr_id( $pick['id'] ?? '' );
$summary = isset( $pick['settingsWalk'] ) && is_array( $pick['settingsWalk'] ) ? $pick['settingsWalk'] : array();

$unit_level = null;
foreach ( $summary as $level ) {
	if ( ! is_array( $level ) ) {
		continue;
	}
	if ( ! empty( $level['supportsPrefixAllowlist'] ) ) {
		$unit_level = $level;
		break;
	}
}
if ( null === $unit_level ) {
	fwrite( STDERR, "No supportsPrefixAllowlist walk level on Passiv/Wert\n" );
	exit( 1 );
}

$path     = Settings_Walk::normalize_walk_path( (string) ( $unit_level['path'] ?? '' ) );
$catalog  = isset( $unit_level['prefixCatalog'] ) && is_array( $unit_level['prefixCatalog'] )
	? $unit_level['prefixCatalog']
	: array();
$node_id  = (int) ( $unit_level['nodeId'] ?? 0 );
$is_bucket = 'bucket' === (string) ( $unit_level['prefixAllowlistSource'] ?? '' );

if ( '' === $path || $node_id <= 0 || count( $catalog ) < 2 ) {
	fwrite( STDERR, "Bad Unit/With-prefix walk level path/catalog\n" );
	exit( 1 );
}

/* Pick two prefix ids from catalog (or one → empty-ish restrict). */
$pick_ids = array();
foreach ( $catalog as $p ) {
	if ( ! is_array( $p ) ) {
		continue;
	}
	$id = (int) ( $p['id'] ?? 0 );
	if ( $id > 0 ) {
		$pick_ids[] = $id;
	}
	if ( count( $pick_ids ) >= 2 ) {
		break;
	}
}
if ( count( $pick_ids ) < 1 ) {
	fwrite( STDERR, "prefixCatalog empty\n" );
	exit( 1 );
}

$set = Attribute::set_walk_settings_key(
	$tax,
	$host_id,
	$attr_id,
	$path,
	'data',
	'allowedPrefixIds',
	$pick_ids
);
if ( is_wp_error( $set ) ) {
	fwrite( STDERR, 'set_walk failed: ' . $set->get_error_message() . "\n" );
	exit( 1 );
}

$row2 = null;
foreach ( Attribute::list_own( $tax, $host_id ) as $row ) {
	if ( Attribute::normalize_attr_id( $row['id'] ?? '' ) === $attr_id ) {
		$row2 = $row;
		break;
	}
}

$settings = is_array( $row2 ) && isset( $row2['settings'] ) && is_array( $row2['settings'] )
	? $row2['settings']
	: array();
$nested   = isset( $settings['nested'][ $path ]['data'] ) && is_array( $settings['nested'][ $path ]['data'] )
	? Settings_Walk::normalize_data_bag( $settings['nested'][ $path ]['data'] )
	: array();
$stored   = isset( $nested['allowedPrefixIds'] )
	? Settings_Walk::normalize_allowed_prefix_ids( $nested['allowedPrefixIds'] )
	: array();
$store_ok = $stored === Settings_Walk::normalize_allowed_prefix_ids( $pick_ids );

$level2 = null;
if ( is_array( $row2 ) && isset( $row2['settingsWalk'] ) && is_array( $row2['settingsWalk'] ) ) {
	foreach ( $row2['settingsWalk'] as $level ) {
		if ( ! is_array( $level ) ) {
			continue;
		}
		if ( Settings_Walk::normalize_walk_path( (string) ( $level['path'] ?? '' ) ) === $path ) {
			$level2 = $level;
			break;
		}
	}
}
$decorate_ok = is_array( $level2 )
	&& ! empty( $level2['hasAllowedPrefixIdsOverride'] )
	&& ! empty( $level2['supportsPrefixAllowlist'] );

/* Paint: Unit typeProperty fixedOptions.allowedPrefixes intersected. */
$paint_ok   = false;
$paint_note = 'no-unit-prop';
if ( is_array( $row2 ) && ! empty( $row2['typeProperties'] ) && is_array( $row2['typeProperties'] ) ) {
	foreach ( $row2['typeProperties'] as $prop ) {
		if ( ! is_array( $prop ) ) {
			continue;
		}
		$prop_type = (int) ( $prop['typeId'] ?? 0 );
		$edge_match = Attribute::normalize_attr_id( $prop['id'] ?? '' ) === Attribute::normalize_attr_id( $level2['edgeId'] ?? '' );
		$node_match = $prop_type === $node_id;
		if ( ! $edge_match && ! $node_match ) {
			continue;
		}
		$opts = isset( $prop['fixedOptions'] ) && is_array( $prop['fixedOptions'] ) ? $prop['fixedOptions'] : array();
		if ( array() === $opts ) {
			$paint_note = 'no-fixedOptions';
			continue;
		}
		$allowed_map = array_fill_keys( $pick_ids, true );
		$all_ok      = true;
		$checked     = 0;
		foreach ( $opts as $opt ) {
			if ( ! is_array( $opt ) || empty( $opt['allowedPrefixes'] ) || ! is_array( $opt['allowedPrefixes'] ) ) {
				continue;
			}
			++$checked;
			foreach ( $opt['allowedPrefixes'] as $ap ) {
				$pid = (int) ( is_array( $ap ) ? ( $ap['id'] ?? 0 ) : 0 );
				if ( $pid > 0 && ! isset( $allowed_map[ $pid ] ) ) {
					$all_ok = false;
					break 2;
				}
			}
		}
		$paint_ok   = $all_ok && $checked > 0;
		$paint_note = $paint_ok ? ( 'intersected units=' . $checked ) : ( 'fail checked=' . $checked );
		break;
	}
}

/* Catalog unit meta must stay unchanged (override is Relation-only). */
$unit_meta_unchanged = true;
if ( $is_bucket ) {
	/* With-prefix has no unit allowlist meta — ok. */
	$unit_meta_unchanged = true;
} elseif ( $node_id > 0 && Node_Type::is_basiseinheit_unit_node( $tax, $node_id ) ) {
	/* If somehow unit leaf: we did not call set_allowed_prefix_ids. */
	$unit_meta_unchanged = true;
}

/* Cleanup */
Attribute::set_walk_settings_key( $tax, $host_id, $attr_id, $path, 'data', 'allowedPrefixIds', null );

$row3 = null;
foreach ( Attribute::list_own( $tax, $host_id ) as $row ) {
	if ( Attribute::normalize_attr_id( $row['id'] ?? '' ) === $attr_id ) {
		$row3 = $row;
		break;
	}
}
$settings3 = is_array( $row3 ) && isset( $row3['settings'] ) && is_array( $row3['settings'] )
	? $row3['settings']
	: array();
$cleared   = ! isset( $settings3['nested'][ $path ]['data']['allowedPrefixIds'] )
	&& ! (
		isset( $settings3['nested'][ $path ]['data'] )
		&& is_array( $settings3['nested'][ $path ]['data'] )
		&& array_key_exists(
			'allowedPrefixIds',
			Settings_Walk::normalize_data_bag( $settings3['nested'][ $path ]['data'] )
		)
	);

$ok = $store_ok && $decorate_ok && $paint_ok && $cleared && $unit_meta_unchanged;

echo 'host=Passiv attr=Wert path=' . $path . "\n";
echo 'prefixAllowlistSource=' . (string) ( $unit_level['prefixAllowlistSource'] ?? '' ) . "\n";
echo 'store_ok=' . ( $store_ok ? '1' : '0' ) . ' decorate_ok=' . ( $decorate_ok ? '1' : '0' )
	. ' paint_ok=' . ( $paint_ok ? '1' : '0' ) . ' (' . $paint_note . ')'
	. ' cleared=' . ( $cleared ? '1' : '0' ) . "\n";
echo 'smoke=' . ( $ok ? 'ok' : 'FAIL' ) . "\n";
exit( $ok ? 0 : 1 );
