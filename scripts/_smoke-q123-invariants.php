<?php
/**
 * Q123 invariants smoke on wtt_fs (Kontakt, Widerstand/Passiv).
 *
 * Asserts:
 * - Own attribute ids are Relation edge ids (sanitize_key / UUID-shaped, not pure term ints)
 * - Own attrs do not target slot terms; Attribute::add creates no slots
 * - Preferred / Default / RO live on the Relation edge when set
 * - Own attrs must not keep RO/Hide/typeExtras keys on host maps (inherited-only)
 * - Settings_Walk exposes nodeCount for nested Wert (Passiv/Widerstand)
 *
 * Usage (from WordPress docroot):
 *   php wp-cli.phar eval-file .../scripts/_smoke-q123-invariants.php
 *
 * @package WP_Taxonomy_Tree
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Load via wp eval-file inside WordPress.\n" );
	exit( 1 );
}

use WTT\Attribute;
use WTT\Node_Type;
use WTT\Relation;
use WTT\Renderer;
use WTT\Settings_Walk;
use WTT\Taxonomy;

$tax = Taxonomy::FS;
$fail = array();
$notes = array();

/**
 * @param mixed $id
 */
function wtt_smoke_q123_is_edge_id( $id ): bool {
	$key = Attribute::normalize_attr_id( $id );
	if ( '' === $key ) {
		return false;
	}
	/* Pure term-id style keys are migrate debt — Relation-only uses UUID / sanitize_key. */
	if ( ctype_digit( $key ) ) {
		return false;
	}
	if ( $key !== sanitize_key( $key ) ) {
		return false;
	}
	/* UUID without dashes = 32 hex; uniqid fallback still ≥ 8. */
	return strlen( $key ) >= 8;
}

/**
 * @return array<string, mixed>|null
 */
function wtt_smoke_q123_find_edge( string $tax, int $host_id, string $attr_id ): ?array {
	foreach ( Relation::list_outgoing( $tax, $host_id ) as $edge ) {
		if ( Attribute::normalize_attr_id( $edge['id'] ?? '' ) === $attr_id ) {
			return is_array( $edge ) ? $edge : null;
		}
	}
	return null;
}

$host_names = array( 'Kontakt', 'Widerstand', 'Passiv' );
$hosts      = array();
foreach ( $host_names as $name ) {
	$term = get_term_by( 'name', $name, $tax );
	if ( $term instanceof WP_Term ) {
		$hosts[ $name ] = $term;
	} else {
		$fail[] = "host_missing:$name";
	}
}

$attr_counts = array();
$wert_row    = null;
$wert_host   = null;

foreach ( $hosts as $name => $term ) {
	$host_id = (int) $term->term_id;
	$rows    = Attribute::list_own( $tax, $host_id );
	$attr_counts[ $name ] = count( $rows );

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			$fail[] = "$name:bad_row";
			continue;
		}
		$attr_id   = Attribute::normalize_attr_id( $row['id'] ?? '' );
		$attr_name = (string) ( $row['name'] ?? '' );
		$label     = "$name/$attr_name";

		if ( ! wtt_smoke_q123_is_edge_id( $attr_id ) ) {
			$fail[] = "$label:bad_attr_id($attr_id)";
		}

		if ( ! empty( $row['legacySlotId'] ) ) {
			$fail[] = "$label:legacy_slot(" . (int) $row['legacySlotId'] . ')';
		}

		$edge = wtt_smoke_q123_find_edge( $tax, $host_id, $attr_id );
		if ( null === $edge ) {
			$fail[] = "$label:edge_missing";
			continue;
		}

		$to_id = (int) ( $edge['toId'] ?? 0 );
		if ( $to_id > 0 && Attribute::is_slot( $to_id ) ) {
			$fail[] = "$label:toId_is_slot($to_id)";
		}

		/*
		 * Own attrs: host maps are inherited-overrides-only — no RO / Hide /
		 * typeExtras keys for this edge id; no name key in fixed_values map.
		 */
		$host_ro = Attribute::get_inherited_readonly_ids( $host_id );
		if ( isset( $host_ro[ $attr_id ] ) ) {
			$fail[] = "$label:own_ro_on_host_map";
		}
		$host_hi = Attribute::get_inherited_hidden_ids( $host_id );
		if ( isset( $host_hi[ $attr_id ] ) ) {
			$fail[] = "$label:own_hide_on_host_map";
		}
		$host_tx = Attribute::get_inherited_type_extras_map( $host_id );
		if ( isset( $host_tx[ $attr_id ] ) ) {
			$fail[] = "$label:own_typeextras_on_host_map";
		}
		$host_fv = Attribute::get_inherited_fixed_values_map( $host_id );
		if ( '' !== $attr_name && isset( $host_fv[ $attr_name ] ) ) {
			$fail[] = "$label:own_default_on_host_map";
		}
		$ov = isset( $row['inheritedHostOverride'] ) && is_array( $row['inheritedHostOverride'] )
			? $row['inheritedHostOverride']
			: array();
		if ( ! empty( $ov['any'] ) ) {
			$fail[] = "$label:own_inheritedHostOverride_any";
		}

		/* When decorate shows RO / default / preferred override — edge must hold SoT. */
		if ( ! empty( $row['readonly'] ) && empty( $edge['readOnly'] ) && empty( $edge['readonly'] ) ) {
			$fail[] = "$label:readonly_not_on_edge";
		}

		$fixed = isset( $row['fixedValues'] ) && is_array( $row['fixedValues'] ) ? $row['fixedValues'] : array();
		if ( ! empty( $fixed ) ) {
			$has_edge_default = ( isset( $edge['default'] ) && is_array( $edge['default'] ) && ! empty( $edge['default'] ) )
				|| ( isset( $edge['defaultSeed'] ) && is_array( $edge['defaultSeed'] ) && ! empty( $edge['defaultSeed'] ) );
			if ( ! $has_edge_default ) {
				/* Own attrs: Default SoT is edge-only (≈ 0.0.431) — host map must not supply. */
				$fail[] = "$label:default_not_on_edge";
			}
		}

		if ( ! empty( $row['preferredRenderOverride'] ) ) {
			$settings = isset( $edge['settings'] ) && is_array( $edge['settings'] ) ? $edge['settings'] : array();
			$view     = isset( $settings['view'] ) && is_array( $settings['view'] ) ? $settings['view'] : array();
			$pref     = '';
			foreach ( array( 'preferredRenderer', 'preferredrenderer' ) as $k ) {
				if ( isset( $view[ $k ] ) && '' !== (string) $view[ $k ] ) {
					$pref = (string) $view[ $k ];
					break;
				}
			}
			if ( '' === $pref ) {
				$fail[] = "$label:preferred_not_on_edge";
			}
		}

		if ( 'Wert' === $attr_name && null === $wert_row ) {
			$wert_row  = $row;
			$wert_host = $term;
		}
	}
}

/* --- Probe: Attribute::add must not create a slot --- */
$kontakt = $hosts['Kontakt'] ?? null;
if ( $kontakt instanceof WP_Term ) {
	$host_id = (int) $kontakt->term_id;
	$type_id = Node_Type::find_type_by_name( $tax, $host_id, 'text' );
	if ( $type_id <= 0 ) {
		$text = get_term_by( 'name', 'text', $tax );
		$type_id = $text instanceof WP_Term ? (int) $text->term_id : 0;
	}
	if ( $type_id <= 0 ) {
		$fail[] = 'add_probe:no_text_type';
	} else {
		$probe_name = 'WTTSmokeQ123Inv';
		foreach ( Attribute::list_own( $tax, $host_id ) as $row ) {
			if ( (string) ( $row['name'] ?? '' ) === $probe_name ) {
				Attribute::remove( $tax, $host_id, $row['id'] ?? '' );
			}
		}

		$created = Attribute::add( $tax, $host_id, $probe_name, $type_id, '0..1' );
		if ( is_wp_error( $created ) ) {
			$fail[] = 'add_probe:create_failed:' . $created->get_error_code();
		} else {
			$new_id = Attribute::normalize_attr_id( $created['id'] ?? '' );
			if ( ! wtt_smoke_q123_is_edge_id( $new_id ) ) {
				$fail[] = "add_probe:bad_id($new_id)";
			}
			if ( ! empty( $created['legacySlotId'] ) ) {
				$fail[] = 'add_probe:legacy_slot';
			}
			$edge = wtt_smoke_q123_find_edge( $tax, $host_id, $new_id );
			$to   = (int) ( is_array( $edge ) ? ( $edge['toId'] ?? 0 ) : 0 );
			if ( $to !== $type_id ) {
				$fail[] = "add_probe:toId_not_type(got=$to want=$type_id)";
			}
			if ( $to > 0 && Attribute::is_slot( $to ) ) {
				$fail[] = 'add_probe:created_slot';
			}
			$slot_meta_on_type = '1' === (string) get_term_meta( $type_id, Attribute::META_KEY_SLOT, true );
			if ( $slot_meta_on_type ) {
				$fail[] = 'add_probe:type_marked_slot';
			}
			$rm = Attribute::remove( $tax, $host_id, $new_id );
			if ( is_wp_error( $rm ) ) {
				$fail[] = 'add_probe:remove_failed:' . $rm->get_error_code();
			} else {
				$notes[] = 'add_probe=no_slot';
			}
		}
	}
} else {
	$fail[] = 'add_probe:no_kontakt';
}

/* --- Probe: Preferred / Default / RO write → edge --- */
$probe_host = $hosts['Kontakt'] ?? null;
$probe_attr = null;
if ( $probe_host instanceof WP_Term ) {
	foreach ( Attribute::list_own( $tax, (int) $probe_host->term_id ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$id = Attribute::normalize_attr_id( $row['id'] ?? '' );
		if ( '' === $id || ! empty( $row['legacySlotId'] ) ) {
			continue;
		}
		/* Prefer scalar text attr (Titel) for default seed. */
		if ( 'Titel' === (string) ( $row['name'] ?? '' ) ) {
			$probe_attr = $row;
			break;
		}
		if ( null === $probe_attr ) {
			$probe_attr = $row;
		}
	}
}

if ( $probe_host instanceof WP_Term && is_array( $probe_attr ) ) {
	$host_id   = (int) $probe_host->term_id;
	$attr_id   = Attribute::normalize_attr_id( $probe_attr['id'] ?? '' );
	$attr_name = (string) ( $probe_attr['name'] ?? '' );
	$edge0     = wtt_smoke_q123_find_edge( $tax, $host_id, $attr_id );
	$settings0 = is_array( $edge0 ) && isset( $edge0['settings'] ) && is_array( $edge0['settings'] )
		? $edge0['settings']
		: array();
	$ro0       = is_array( $edge0 ) && ( ! empty( $edge0['readOnly'] ) || ! empty( $edge0['readonly'] ) );
	$default0  = is_array( $edge0 ) && isset( $edge0['default'] ) && is_array( $edge0['default'] )
		? $edge0['default']
		: null;

	$marker_pref = Renderer::Compact->value;
	$set_pref    = Attribute::set_preferred_render( $tax, $host_id, $attr_id, $marker_pref );
	if ( is_wp_error( $set_pref ) ) {
		$fail[] = 'edge_write:preferred_fail:' . $set_pref->get_error_code();
	} else {
		$edge1 = wtt_smoke_q123_find_edge( $tax, $host_id, $attr_id );
		$view1 = is_array( $edge1 ) && isset( $edge1['settings']['view'] ) && is_array( $edge1['settings']['view'] )
			? $edge1['settings']['view']
			: array();
		$got   = (string) ( $view1['preferredRenderer'] ?? $view1['preferredrenderer'] ?? '' );
		if ( $got !== $marker_pref ) {
			$fail[] = "edge_write:preferred_mismatch($got)";
		} else {
			$notes[] = 'preferred_on_edge=yes';
		}
	}

	$marker_def = array( 'wtt-smoke-q123-inv-' . gmdate( 'His' ) );
	$set_def    = Attribute::set_fixed_values( $tax, $host_id, $attr_id, $marker_def );
	if ( is_wp_error( $set_def ) ) {
		$fail[] = 'edge_write:default_fail:' . $set_def->get_error_code();
	} else {
		$edge2 = wtt_smoke_q123_find_edge( $tax, $host_id, $attr_id );
		$got   = is_array( $edge2 ) && isset( $edge2['default'] ) && is_array( $edge2['default'] )
			? $edge2['default']
			: array();
		if ( $got !== $marker_def ) {
			$fail[] = 'edge_write:default_mismatch';
		} else {
			$notes[] = 'default_on_edge=yes';
		}
	}

	$set_ro = Attribute::set_readonly( $tax, $host_id, $attr_id, true );
	if ( is_wp_error( $set_ro ) ) {
		$fail[] = 'edge_write:ro_fail:' . $set_ro->get_error_code();
	} else {
		$edge3 = wtt_smoke_q123_find_edge( $tax, $host_id, $attr_id );
		if ( empty( $edge3['readOnly'] ) && empty( $edge3['readonly'] ) ) {
			$fail[] = 'edge_write:ro_not_on_edge';
		} else {
			$host_ro = Attribute::get_readonly_ids( $host_id );
			if ( isset( $host_ro[ $attr_id ] ) ) {
				$fail[] = 'edge_write:ro_still_on_host_map';
			} else {
				$notes[] = 'readonly_on_edge=yes';
			}
		}
	}

	/* Restore prior edge state. */
	Attribute::set_preferred_render( $tax, $host_id, $attr_id, '' );
	if ( ! empty( $settings0 ) ) {
		Relation::update_settings( $tax, $host_id, $attr_id, $settings0 );
	}
	if ( null === $default0 ) {
		Attribute::set_fixed_values( $tax, $host_id, $attr_id, array() );
	} else {
		Attribute::set_fixed_values( $tax, $host_id, $attr_id, $default0 );
	}
	Attribute::set_readonly( $tax, $host_id, $attr_id, $ro0 );
	$notes[] = "edge_write_host=$attr_name";
} else {
	$fail[] = 'edge_write:no_probe_attr';
}

/* --- Inherited host-map override flags (Widerstand inherits from Passiv) --- */
$wid = $hosts['Widerstand'] ?? null;
if ( $wid instanceof WP_Term ) {
	$wid_id   = (int) $wid->term_id;
	$eff      = Attribute::effective_list( $tax, $wid_id );
	$inh_rows = 0;
	$ov_probe = null;
	foreach ( $eff as $row ) {
		if ( empty( $row['inherited'] ) ) {
			continue;
		}
		++$inh_rows;
		$ov = isset( $row['inheritedHostOverride'] ) && is_array( $row['inheritedHostOverride'] )
			? $row['inheritedHostOverride']
			: null;
		if ( ! is_array( $ov ) || ! array_key_exists( 'any', $ov ) ) {
			$fail[] = 'inherited_override:missing_flags:' . (string) ( $row['name'] ?? '?' );
			continue;
		}
		if ( null === $ov_probe ) {
			$ov_probe = $row;
		}
	}
	if ( $inh_rows <= 0 ) {
		$fail[] = 'inherited_override:no_inherited_on_Widerstand';
	} else {
		$notes[] = "inherited_host_map_flags=yes (rows=$inh_rows)";
		/* Round-trip: plant Hide cover-up on first inherited, assert flag, restore. */
		if ( is_array( $ov_probe ) ) {
			$aid = Attribute::normalize_attr_id( $ov_probe['id'] ?? '' );
			$was = ! empty( $ov_probe['hidden'] );
			$set = Attribute::set_hidden( $tax, $wid_id, $aid, true );
			if ( is_wp_error( $set ) ) {
				$fail[] = 'inherited_override:hide_fail:' . $set->get_error_code();
			} else {
				$again = null;
				foreach ( Attribute::effective_list( $tax, $wid_id ) as $r ) {
					if ( Attribute::normalize_attr_id( $r['id'] ?? '' ) === $aid ) {
						$again = $r;
						break;
					}
				}
				$flags = is_array( $again ) && isset( $again['inheritedHostOverride'] )
					? $again['inheritedHostOverride']
					: array();
				if ( empty( $flags['hidden'] ) || empty( $flags['any'] ) ) {
					$fail[] = 'inherited_override:hide_flag_missing';
				} else {
					$host_hi = Attribute::get_inherited_hidden_ids( $wid_id );
					if ( ! isset( $host_hi[ $aid ] ) ) {
						$fail[] = 'inherited_override:hide_not_on_host_map';
					} else {
						$notes[] = 'inherited_hide_host_map=yes';
					}
				}
				Attribute::set_hidden( $tax, $wid_id, $aid, $was );
			}
		}
	}
} else {
	$fail[] = 'inherited_override:no_Widerstand';
}

/* --- Settings_Walk nodeCount for Wert --- */
$walk_ok = false;
$walk_nc = 0;
if ( is_array( $wert_row ) && $wert_host instanceof WP_Term ) {
	$meta    = isset( $wert_row['settingsWalkMeta'] ) && is_array( $wert_row['settingsWalkMeta'] )
		? $wert_row['settingsWalkMeta']
		: array();
	$walk_nc = (int) ( $meta['nodeCount'] ?? 0 );
	if ( $walk_nc > 0 ) {
		$walk_ok = true;
		$notes[] = 'wert_host=' . $wert_host->name;
	} else {
		/* Re-resolve via Settings_Walk directly. */
		$type_id = (int) ( $wert_row['typeId'] ?? 0 );
		$settings = isset( $wert_row['settings'] ) && is_array( $wert_row['settings'] )
			? $wert_row['settings']
			: null;
		$resolved = Settings_Walk::resolve_preferred_render( $tax, $type_id, $settings, 0 );
		$walk_nc  = (int) ( $resolved['walkMeta']['nodeCount'] ?? 0 );
		if ( $walk_nc > 0 ) {
			$walk_ok = true;
			$notes[] = 'wert_nodeCount_direct=' . $walk_nc;
		} else {
			$fail[] = 'wert:nodeCount_zero';
		}
	}
} else {
	/* Fallback: scan Passiv then Widerstand for any nested walk. */
	foreach ( array( 'Passiv', 'Widerstand' ) as $hn ) {
		if ( ! isset( $hosts[ $hn ] ) ) {
			continue;
		}
		foreach ( Attribute::list_own( $tax, (int) $hosts[ $hn ]->term_id ) as $row ) {
			$meta = isset( $row['settingsWalkMeta'] ) && is_array( $row['settingsWalkMeta'] )
				? $row['settingsWalkMeta']
				: array();
			$nc   = (int) ( $meta['nodeCount'] ?? 0 );
			if ( $nc > 0 && 'Wert' === (string) ( $row['name'] ?? '' ) ) {
				$walk_ok = true;
				$walk_nc = $nc;
				$notes[] = "wert_fallback={$hn}/$nc";
				break 2;
			}
		}
	}
	if ( ! $walk_ok ) {
		$fail[] = 'wert:attr_not_found';
	}
}

$ok = empty( $fail );

echo 'WTT_VERSION=' . ( defined( 'WTT_VERSION' ) ? WTT_VERSION : '?' ) . PHP_EOL;
foreach ( $attr_counts as $hn => $c ) {
	echo "host_{$hn}_attrs={$c}" . PHP_EOL;
}
echo 'wert_nodeCount=' . $walk_nc . PHP_EOL;
echo 'wert_walk=' . ( $walk_ok ? 'yes' : 'no' ) . PHP_EOL;
foreach ( $notes as $n ) {
	echo $n . PHP_EOL;
}
if ( ! $ok ) {
	echo 'failures=' . count( $fail ) . PHP_EOL;
	foreach ( $fail as $f ) {
		echo 'FAIL ' . $f . PHP_EOL;
	}
}
echo 'invariants=' . ( $ok ? 'yes' : 'no' ) . PHP_EOL;
echo 'smoke=' . ( $ok ? 'ok' : 'fail' ) . PHP_EOL;

exit( $ok ? 0 : 1 );
