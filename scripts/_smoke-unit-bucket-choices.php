<?php
/**
 * Smoke: Basiseinheiten/With prefix is a unit bucket → CatalogChoice + Walk Choices.
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run via wp-load / eval-file.\n" );
	exit( 1 );
}

$tax = 'wtt_fs';
$ok  = true;

$with = 0;
foreach (
	array(
		array( 'Fallstudie', 'Definition', 'Konstanten', 'Basiseinheiten', 'With prefix' ),
		array( 'Fallstudie', 'Definition', 'Data Types', 'Unit', 'With prefix' ),
	) as $path
) {
	$id = \WTT\Case_Data::find_term_by_path( $tax, $path );
	if ( $id > 0 && ! \WTT\Trash::is_trashed( $id ) ) {
		$with = $id;
		break;
	}
}

if ( $with <= 0 ) {
	echo "SKIP: live With prefix not found\n";
	exit( 0 );
}

if ( ! \WTT\Node_Type::is_unit_prefix_bucket( $tax, $with ) ) {
	echo "FAIL: is_unit_prefix_bucket({$with})\n";
	$ok = false;
}

$opts = \WTT\Attribute::catalog_choice_options_for_type( $tax, $with );
if ( count( $opts ) < 1 ) {
	echo "FAIL: catalog_choice_options empty\n";
	$ok = false;
} else {
	echo 'choice_opts=' . count( $opts ) . "\n";
}

$ut = get_term_by( 'name', 'Unit type', $tax );
if ( $ut instanceof WP_Term ) {
	foreach ( \WTT\Attribute::list( $tax, (int) $ut->term_id ) as $a ) {
		if ( 'Base unit' !== (string) ( $a['name'] ?? '' ) ) {
			continue;
		}
		$mode = (string) ( $a['fixedMode'] ?? '' );
		$n    = is_array( $a['fixedOptions'] ?? null ) ? count( $a['fixedOptions'] ) : 0;
		echo "Base unit fixedMode={$mode} opts={$n}\n";
		if ( 'catalog' !== $mode || $n < 1 ) {
			echo "FAIL: Base unit not CatalogChoice\n";
			$ok = false;
		}
	}
}

$passiv = get_term_by( 'name', 'Passiv', $tax );
if ( $passiv instanceof WP_Term ) {
	foreach ( \WTT\Attribute::list( $tax, (int) $passiv->term_id ) as $a ) {
		if ( 'Wert' !== (string) ( $a['name'] ?? '' ) ) {
			continue;
		}
		\WTT\Attribute::clear_settings_walk_cache( (int) $passiv->term_id, (string) $a['id'] );
		$r = \WTT\Attribute::ensure_settings_walk_cache(
			$tax,
			(int) $passiv->term_id,
			(string) $a['id'],
			true
		);
		if ( is_wp_error( $r ) ) {
			echo 'FAIL walk: ' . $r->get_error_message() . "\n";
			$ok = false;
			break;
		}
		$found = false;
		foreach ( $r['settingsWalk'] ?? array() as $lv ) {
			if ( 'Base unit' !== (string) ( $lv['edgeName'] ?? '' ) ) {
				continue;
			}
			$found = true;
			$sc    = ! empty( $lv['supportsChoiceFilter'] );
			$co    = is_array( $lv['choiceOptions'] ?? null ) ? count( $lv['choiceOptions'] ) : 0;
			echo "walk Base unit choice=" . ( $sc ? 'Y' : 'n' ) . " opts={$co}\n";
			if ( ! $sc || $co < 1 ) {
				echo "FAIL: walk Base unit Choices missing\n";
				$ok = false;
			}
		}
		if ( ! $found ) {
			echo "FAIL: walk missing Base unit level\n";
			$ok = false;
		}
	}
}

echo $ok ? "smoke=ok\n" : "smoke=FAIL\n";
exit( $ok ? 0 : 1 );
