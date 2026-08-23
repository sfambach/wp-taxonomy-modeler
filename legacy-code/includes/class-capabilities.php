<?php
/**
 * Capability helpers for taxonomy terms.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves manage/edit/delete caps from a taxonomy object.
 */
final class Capabilities {

	public static function manage_terms( string $taxonomy ): string {
		$tax = get_taxonomy( $taxonomy );
		if ( $tax instanceof \WP_Taxonomy ) {
			return (string) $tax->cap->manage_terms;
		}

		return 'manage_categories';
	}

	public static function edit_terms( string $taxonomy ): string {
		$tax = get_taxonomy( $taxonomy );
		if ( $tax instanceof \WP_Taxonomy ) {
			return (string) $tax->cap->edit_terms;
		}

		return 'manage_categories';
	}

	public static function delete_terms( string $taxonomy ): string {
		$tax = get_taxonomy( $taxonomy );
		if ( $tax instanceof \WP_Taxonomy ) {
			return (string) $tax->cap->delete_terms;
		}

		return 'manage_categories';
	}

	public static function user_can_manage( string $taxonomy ): bool {
		return current_user_can( self::manage_terms( $taxonomy ) );
	}
}
