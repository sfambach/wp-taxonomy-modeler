<?php
/**
 * Admin-AJAX handlers for the tree UI.
 *
 * @package WP_Taxonomy_Tree
 */

declare(strict_types=1);

namespace WTT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Secure AJAX endpoints for tree mutations.
 */
final class Tree_Ajax {

	public const NONCE_ACTION = 'wtt_tree';

	public static function register(): void {
		add_action( 'wp_ajax_wtt_get_tree', array( self::class, 'get_tree' ) );
		add_action( 'wp_ajax_wtt_get_node', array( self::class, 'get_node' ) );
		add_action( 'wp_ajax_wtt_create_term', array( self::class, 'create_term' ) );
		add_action( 'wp_ajax_wtt_copy_term', array( self::class, 'copy_term' ) );
		add_action( 'wp_ajax_wtt_copy_terms', array( self::class, 'copy_terms' ) );
		add_action( 'wp_ajax_wtt_delete_term', array( self::class, 'delete_term' ) );
		add_action( 'wp_ajax_wtt_empty_trash', array( self::class, 'empty_trash' ) );
		add_action( 'wp_ajax_wtt_reset_demo', array( self::class, 'reset_demo' ) );
		add_action( 'wp_ajax_wtt_set_node_type', array( self::class, 'set_node_type' ) );
		add_action( 'wp_ajax_wtt_set_node_required', array( self::class, 'set_node_required' ) );
		add_action( 'wp_ajax_wtt_set_node_has_footer', array( self::class, 'set_node_has_footer' ) );
		add_action( 'wp_ajax_wtt_set_node_fixed', array( self::class, 'set_node_fixed' ) );
		add_action( 'wp_ajax_wtt_set_branch_child', array( self::class, 'set_branch_child' ) );
		add_action( 'wp_ajax_wtt_get_type_branch', array( self::class, 'get_type_branch' ) );
		add_action( 'wp_ajax_wtt_save_node_settings', array( self::class, 'save_node_settings' ) );
		add_action( 'wp_ajax_wtt_move_term', array( self::class, 'move_term' ) );
		add_action( 'wp_ajax_wtt_reparent_term', array( self::class, 'reparent_term' ) );
		add_action( 'wp_ajax_wtt_reparent_terms', array( self::class, 'reparent_terms' ) );
		add_action( 'wp_ajax_wtt_add_relation', array( self::class, 'add_relation' ) );
		add_action( 'wp_ajax_wtt_remove_relation', array( self::class, 'remove_relation' ) );
		add_action( 'wp_ajax_wtt_duplicate_relation', array( self::class, 'duplicate_relation' ) );
		add_action( 'wp_ajax_wtt_move_relation', array( self::class, 'move_relation' ) );
		add_action( 'wp_ajax_wtt_update_relation_type', array( self::class, 'update_relation_type' ) );
		add_action( 'wp_ajax_wtt_update_relation_multiplicity', array( self::class, 'update_relation_multiplicity' ) );
		add_action( 'wp_ajax_wtt_update_relation_to', array( self::class, 'update_relation_to' ) );
		add_action( 'wp_ajax_wtt_fix_table_band_fields', array( self::class, 'fix_table_band_fields' ) );
		add_action( 'wp_ajax_wtt_create_node_ref_target', array( self::class, 'create_node_ref_target' ) );
		add_action( 'wp_ajax_wtt_add_attribute', array( self::class, 'add_attribute' ) );
		add_action( 'wp_ajax_wtt_update_attribute', array( self::class, 'update_attribute' ) );
		add_action( 'wp_ajax_wtt_remove_attribute', array( self::class, 'remove_attribute' ) );
		add_action( 'wp_ajax_wtt_move_attribute_to_parent', array( self::class, 'move_attribute_to_parent' ) );
		add_action( 'wp_ajax_wtt_move_attribute_to_child', array( self::class, 'move_attribute_to_child' ) );
		add_action( 'wp_ajax_wtt_reorder_attribute', array( self::class, 'reorder_attribute' ) );
		add_action( 'wp_ajax_wtt_set_attribute_hidden', array( self::class, 'set_attribute_hidden' ) );
		add_action( 'wp_ajax_wtt_set_attribute_readonly', array( self::class, 'set_attribute_readonly' ) );
		add_action( 'wp_ajax_wtt_set_attribute_type', array( self::class, 'set_attribute_type' ) );
		add_action( 'wp_ajax_wtt_set_attribute_multiplicity', array( self::class, 'set_attribute_multiplicity' ) );
		add_action( 'wp_ajax_wtt_set_attribute_binding', array( self::class, 'set_attribute_binding' ) );
		add_action( 'wp_ajax_wtt_set_attribute_fixed', array( self::class, 'set_attribute_fixed' ) );
		add_action( 'wp_ajax_wtt_set_attribute_type_extras', array( self::class, 'set_attribute_type_extras' ) );
		add_action( 'wp_ajax_wtt_duplicate_attribute', array( self::class, 'duplicate_attribute' ) );
		add_action( 'wp_ajax_wtt_set_enum_values', array( self::class, 'set_enum_values' ) );
	}

	public static function get_tree(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! Capabilities::user_can_manage( $taxonomy ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		wp_send_json_success(
			array(
				'taxonomy' => $taxonomy,
				'tree'     => Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	public static function get_node(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! Capabilities::user_can_manage( $taxonomy ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$node    = Tree_Model::get_node( $taxonomy, $term_id );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success( array( 'node' => $node ) );
	}

	public static function create_term(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$parent = isset( $_POST['parent'] ) ? absint( wp_unslash( $_POST['parent'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$node = Tree_Model::create_term( $taxonomy, $name, $parent );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success(
			array(
				'node' => $node,
				'tree' => Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	/**
	 * Create a catalog leaf under node_ref ref_scope (mini-form) and return refreshed options.
	 */
	public static function create_node_ref_target(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$scope_id  = isset( $_POST['ref_scope'] ) ? absint( wp_unslash( $_POST['ref_scope'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$slot_id   = isset( $_POST['slot_id'] ) ? absint( wp_unslash( $_POST['slot_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$parent_id = isset( $_POST['parent'] ) ? absint( wp_unslash( $_POST['parent'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$name      = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$fields_raw = isset( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_string( $fields_raw ) ) {
			$decoded    = json_decode( $fields_raw, true );
			$fields_raw = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $fields_raw ) ) {
			$fields_raw = array();
		}
		$fields = array();
		foreach ( $fields_raw as $key => $value ) {
			$fields[ (string) $key ] = is_scalar( $value ) ? (string) $value : '';
		}
		if ( '' === $name && isset( $fields['name'] ) ) {
			$name = sanitize_text_field( (string) $fields['name'] );
		}

		$result = Composition::create_node_ref_target(
			$taxonomy,
			$scope_id,
			$name,
			$fields,
			$slot_id,
			$parent_id
		);
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success(
			array(
				'option'         => $result['option'] ?? null,
				'nodeRefOptions' => $result['nodeRefOptions'] ?? array(),
				'tree'           => Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	public static function copy_term(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result  = Tree_Model::copy_terms_subset( $taxonomy, array( $term_id ) );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$nodes = $result['nodes'] ?? array();
		$node  = $nodes[0] ?? null;
		if ( ! is_array( $node ) ) {
			self::send_error( new \WP_Error( 'wtt_copy_failed', __( 'Copy failed.', 'wp-taxonomy-tree' ) ) );
		}

		wp_send_json_success(
			array(
				'node'  => $node,
				'nodes' => $nodes,
				'idMap' => $result['idMap'] ?? array(),
				'tree'  => $result['tree'] ?? Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	public static function copy_terms(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$raw = isset( $_POST['term_ids'] ) ? wp_unslash( $_POST['term_ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array_filter( array_map( 'absint', explode( ',', $raw ) ) );
		}
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$term_ids = array();
		foreach ( $raw as $id ) {
			$term_ids[] = absint( $id );
		}

		$result = Tree_Model::copy_terms_subset( $taxonomy, $term_ids );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success(
			array(
				'nodes' => $result['nodes'] ?? array(),
				'idMap' => $result['idMap'] ?? array(),
				'tree'  => $result['tree'] ?? Tree_Model::get_tree( $taxonomy ),
				'node'  => ! empty( $result['nodes'] ) ? $result['nodes'][ count( $result['nodes'] ) - 1 ] : null,
			)
		);
	}

	public static function delete_term(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::delete_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$mode    = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'leaf'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $mode, array( 'leaf', 'promote', 'cascade' ), true ) ) {
			$mode = 'leaf';
		}

		$result = Tree_Model::delete_term( $taxonomy, $term_id, $mode );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success(
			array(
				'tree' => Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	public static function empty_trash(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::delete_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$result = Tree_Model::empty_trash( $taxonomy );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success(
			array(
				'deleted' => (int) ( $result['deleted'] ?? 0 ),
				'tree'    => Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	public static function reset_demo(): void {
		self::verify_request();

		if ( ! Settings::is_test_mode() ) {
			self::send_error(
				new \WP_Error(
					'wtt_test_mode_off',
					__( 'Reset test tree is only available in test mode.', 'wp-taxonomy-tree' ),
					array( 'status' => 403 )
				)
			);
		}

		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		$result = Case_Data::reset( $taxonomy );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success(
			array(
				'deleted'  => $result['deleted'],
				'created'  => $result['created'],
				'existing' => $result['existing'],
				'tree'     => Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	public static function set_node_type(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type_id = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Node_Type::set_type_id( $taxonomy, $term_id, $type_id );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$node = Tree_Model::get_node( $taxonomy, $term_id );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success( array( 'node' => $node ) );
	}

	public static function set_node_required(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id  = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$required = isset( $_POST['required'] ) ? (string) wp_unslash( $_POST['required'] ) : '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$required = in_array( $required, array( '1', 'true', 'yes' ), true );

		$result = Node_Type::set_required( $taxonomy, $term_id, $required );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$node = Tree_Model::get_node( $taxonomy, $term_id );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success( array( 'node' => $node ) );
	}

	public static function set_node_has_footer(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id    = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$has_footer = isset( $_POST['has_footer'] ) ? (string) wp_unslash( $_POST['has_footer'] ) : '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$has_footer = in_array( $has_footer, array( '1', 'true', 'yes' ), true );

		$result = Node_Type::set_has_footer( $taxonomy, $term_id, $has_footer );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$node = Tree_Model::get_node( $taxonomy, $term_id );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success( array( 'node' => $node ) );
	}

	public static function set_node_fixed(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id        = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$fixed_node_id  = isset( $_POST['fixed_node_id'] ) ? absint( wp_unslash( $_POST['fixed_node_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Node_Type::set_fixed_node_id( $taxonomy, $term_id, $fixed_node_id );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$node = Tree_Model::get_node( $taxonomy, $term_id );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success( array( 'node' => $node ) );
	}

	public static function set_branch_child(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id  = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$child_id = isset( $_POST['child_id'] ) ? absint( wp_unslash( $_POST['child_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$enabled  = isset( $_POST['enabled'] ) ? (string) wp_unslash( $_POST['enabled'] ) : '1'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$enabled  = in_array( $enabled, array( '1', 'true', 'yes' ), true );

		$result = Node_Type::set_branch_child_enabled( $taxonomy, $term_id, $child_id, $enabled );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$node = Tree_Model::get_node( $taxonomy, $term_id );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success( array( 'node' => $node ) );
	}

	public static function get_type_branch(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$type_id = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$quantity = Node_Type::get_quantity_schema_for_type( $taxonomy, $type_id );
		$branch   = null !== $quantity ? null : Node_Type::build_type_branch( $taxonomy, $type_id, array() );

		wp_send_json_success(
			array(
				'typeBranch'     => $branch,
				'typeName'       => $branch['typeName'] ?? ( $quantity['unitName'] ?? '' ),
				'isSet'          => $type_id > 0 ? Node_Type::is_set_typed( $taxonomy, $type_id ) : false,
				'isUnitQuantity' => null !== $quantity,
				'quantitySchema' => $quantity,
			)
		);
	}

	public static function save_node_settings(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type_id = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$short_description = isset( $_POST['short_description'] ) ? sanitize_text_field( wp_unslash( $_POST['short_description'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$is_datatype_post = '__omit__';
		if ( isset( $_POST['is_datatype'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_dt = (string) wp_unslash( $_POST['is_datatype'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( 'inherit' === $raw_dt || '' === $raw_dt ) {
				$is_datatype_post = null;
			} else {
				$is_datatype_post = in_array( $raw_dt, array( '1', 'true', 'yes' ), true );
			}
		}

		$is_abstract_post = '__omit__';
		if ( isset( $_POST['is_abstract'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_ab = (string) wp_unslash( $_POST['is_abstract'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			/* Q77: abstract is local-only — no inherit tri-state. */
			$is_abstract_post = in_array( $raw_ab, array( '1', 'true', 'yes' ), true );
		}

		$required = isset( $_POST['required'] ) ? (string) wp_unslash( $_POST['required'] ) : '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$required = in_array( $required, array( '1', 'true', 'yes' ), true );

		$has_footer = isset( $_POST['has_footer'] ) ? (string) wp_unslash( $_POST['has_footer'] ) : '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$has_footer = in_array( $has_footer, array( '1', 'true', 'yes' ), true );

		$footer_op = null;
		if ( isset( $_POST['footer_op'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$footer_op = sanitize_key( (string) wp_unslash( $_POST['footer_op'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		$set_separator = isset( $_POST['set_separator'] ) ? (string) wp_unslash( $_POST['set_separator'] ) : '/'; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$set_separator = sanitize_text_field( $set_separator );

		$set_join_units = true;
		if ( isset( $_POST['set_join_units'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_join = (string) wp_unslash( $_POST['set_join_units'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$set_join_units = in_array( $raw_join, array( '1', 'true', 'yes' ), true );
		}

		$set_label_children = true;
		if ( isset( $_POST['set_label_children'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_label = (string) wp_unslash( $_POST['set_label_children'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$set_label_children = in_array( $raw_label, array( '1', 'true', 'yes' ), true );
		}

		$type_inheriting = isset( $_POST['type_inheriting'] ) ? (string) wp_unslash( $_POST['type_inheriting'] ) : '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type_inheriting = in_array( $type_inheriting, array( '1', 'true', 'yes' ), true );

		$type_override = isset( $_POST['type_override'] ) ? (string) wp_unslash( $_POST['type_override'] ) : '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type_override = in_array( $type_override, array( '1', 'true', 'yes' ), true );

		$fixed_enabled = isset( $_POST['fixed_enabled'] ) ? (string) wp_unslash( $_POST['fixed_enabled'] ) : '0'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$fixed_enabled = in_array( $fixed_enabled, array( '1', 'true', 'yes' ), true );
		$fixed_literal = isset( $_POST['fixed_literal'] ) ? sanitize_text_field( wp_unslash( $_POST['fixed_literal'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		// textarea fixed values may need newlines — allow lightly.
		if ( isset( $_POST['fixed_literal'] ) && is_string( $_POST['fixed_literal'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$fixed_literal = sanitize_textarea_field( wp_unslash( $_POST['fixed_literal'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		$fixed_node_id = isset( $_POST['fixed_node_id'] ) ? absint( wp_unslash( $_POST['fixed_node_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ref_scope_id  = isset( $_POST['ref_scope_id'] ) ? absint( wp_unslash( $_POST['ref_scope_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$field_multiplicity = isset( $_POST['field_multiplicity'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['field_multiplicity'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '0..1';

		$allowed_ref_raw = isset( $_POST['allowed_ref_ids'] ) ? wp_unslash( $_POST['allowed_ref_ids'] ) : '[]'; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_string( $allowed_ref_raw ) ) {
			$decoded_refs    = json_decode( $allowed_ref_raw, true );
			$allowed_ref_raw = is_array( $decoded_refs ) ? $decoded_refs : array();
		}
		if ( ! is_array( $allowed_ref_raw ) ) {
			$allowed_ref_raw = array();
		}

		$disabled_raw = isset( $_POST['disabled_branch_ids'] ) ? wp_unslash( $_POST['disabled_branch_ids'] ) : '[]'; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_string( $disabled_raw ) ) {
			$decoded = json_decode( $disabled_raw, true );
			$disabled_raw = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $disabled_raw ) ) {
			$disabled_raw = array();
		}

		$multi_raw = isset( $_POST['prefix_multiplikators'] ) ? wp_unslash( $_POST['prefix_multiplikators'] ) : '{}'; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_string( $multi_raw ) ) {
			$decoded_multi = json_decode( $multi_raw, true );
			$multi_raw     = is_array( $decoded_multi ) ? $decoded_multi : array();
		}
		if ( ! is_array( $multi_raw ) ) {
			$multi_raw = array();
		}

		$prefix_root_to_si = null;
		if ( isset( $_POST['prefix_root_to_si'] ) && is_numeric( wp_unslash( $_POST['prefix_root_to_si'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$prefix_root_to_si = (float) wp_unslash( $_POST['prefix_root_to_si'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		$media_allow_upload = null;
		if ( isset( $_POST['media_allow_upload'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_up = (string) wp_unslash( $_POST['media_allow_upload'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$media_allow_upload = in_array( $raw_up, array( '1', 'true', 'yes' ), true );
		}
		$media_allow_url = null;
		if ( isset( $_POST['media_allow_url'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw_url = (string) wp_unslash( $_POST['media_allow_url'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$media_allow_url = in_array( $raw_url, array( '1', 'true', 'yes' ), true );
		}
		$media_allowed_kinds = null;
		if ( isset( $_POST['media_allowed_kinds'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$kinds_raw = wp_unslash( $_POST['media_allowed_kinds'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_string( $kinds_raw ) ) {
				$decoded = json_decode( $kinds_raw, true );
				$kinds_raw = is_array( $decoded ) ? $decoded : array();
			}
			$media_allowed_kinds = is_array( $kinds_raw ) ? $kinds_raw : array();
		}

		$date_mode = null;
		if ( isset( $_POST['date_mode'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$date_mode = sanitize_key( (string) wp_unslash( $_POST['date_mode'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		$preferred_render = null;
		if ( isset( $_POST['preferred_render'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$preferred_render = sanitize_key( (string) wp_unslash( $_POST['preferred_render'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		$type_props = null;
		if ( isset( $_POST['type_props'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$props_raw = wp_unslash( $_POST['type_props'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_string( $props_raw ) ) {
				$decoded_props = json_decode( $props_raw, true );
				$props_raw     = is_array( $decoded_props ) ? $decoded_props : array();
			}
			$type_props = is_array( $props_raw ) ? $props_raw : array();
		}

		$prop_bindings = null;
		if ( isset( $_POST['prop_bindings'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$bind_raw = wp_unslash( $_POST['prop_bindings'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_string( $bind_raw ) ) {
				$decoded_bind = json_decode( $bind_raw, true );
				$bind_raw     = is_array( $decoded_bind ) ? $decoded_bind : array();
			}
			$prop_bindings = is_array( $bind_raw ) ? $bind_raw : array();
		}

		$save_args = array(
			'name'                  => $name,
			'description'           => $description,
			'short_description'     => $short_description,
			'type_id'               => $type_id,
			'type_inheriting'       => $type_inheriting,
			'type_override'         => $type_override,
			'required'              => $required,
			'has_footer'            => $has_footer,
			'set_separator'         => $set_separator,
			'set_join_units'        => $set_join_units,
			'set_label_children'    => $set_label_children,
			'fixed_enabled'         => $fixed_enabled,
			'fixed_literal'         => $fixed_literal,
			'fixed_node_id'         => $fixed_node_id,
			'ref_scope_id'          => $ref_scope_id,
			'field_multiplicity'    => $field_multiplicity,
			'allowed_ref_ids'       => $allowed_ref_raw,
			'disabled_branch_ids'   => $disabled_raw,
			'prefix_multiplikators' => $multi_raw,
			'prefix_root_to_si'     => $prefix_root_to_si,
		);
		if ( null !== $footer_op ) {
			$save_args['footer_op'] = $footer_op;
		}
		if ( '__omit__' !== $is_datatype_post ) {
			$save_args['is_datatype'] = $is_datatype_post;
		}
		if ( '__omit__' !== $is_abstract_post ) {
			$save_args['is_abstract'] = $is_abstract_post;
		}
		if ( null !== $media_allow_upload ) {
			$save_args['media_allow_upload'] = $media_allow_upload;
		}
		if ( null !== $media_allow_url ) {
			$save_args['media_allow_url'] = $media_allow_url;
		}
		if ( null !== $media_allowed_kinds ) {
			$save_args['media_allowed_kinds'] = $media_allowed_kinds;
		}
		if ( null !== $date_mode ) {
			$save_args['date_mode'] = $date_mode;
		}
		if ( null !== $preferred_render ) {
			$save_args['preferred_render'] = $preferred_render;
		}
		if ( null !== $type_props ) {
			$save_args['type_props'] = $type_props;
		}
		if ( null !== $prop_bindings ) {
			$save_args['prop_bindings'] = $prop_bindings;
		}

		$result = Node_Type::save_node_settings( $taxonomy, $term_id, $save_args );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		/*
		 * Do not hard-fail the AJAX response after a successful write.
		 * Table rule violations stay visible via node.tableValidation / the banner;
		 * blocking here made clients treat binding saves as failed and race with
		 * older in-flight empties.
		 */
		$node = Tree_Model::get_node( $taxonomy, $term_id );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success(
			array(
				'node' => $node,
				'tree' => Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	public static function move_term(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id   = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$direction = isset( $_POST['direction'] ) ? sanitize_key( wp_unslash( $_POST['direction'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Tree_Model::move_term( $taxonomy, $term_id, $direction );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		wp_send_json_success(
			array(
				'tree' => Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	public static function reparent_term(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id       = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$new_parent_id = isset( $_POST['parent'] ) ? absint( wp_unslash( $_POST['parent'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Tree_Model::reparent_term( $taxonomy, $term_id, $new_parent_id );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$node = Tree_Model::get_node( $taxonomy, $term_id );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success(
			array(
				'node' => $node,
				'tree' => Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	public static function reparent_terms(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$raw = isset( $_POST['term_ids'] ) ? wp_unslash( $_POST['term_ids'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array_filter( array_map( 'absint', explode( ',', $raw ) ) );
		}
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$term_ids = array();
		foreach ( $raw as $id ) {
			$term_ids[] = absint( $id );
		}

		$new_parent_id = isset( $_POST['parent'] ) ? absint( wp_unslash( $_POST['parent'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$before_id     = isset( $_POST['before'] ) ? absint( wp_unslash( $_POST['before'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Tree_Model::reparent_terms( $taxonomy, $term_ids, $new_parent_id, $before_id );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$moved = $result['moved'] ?? array();
		$last  = ! empty( $moved ) ? (int) $moved[ count( $moved ) - 1 ] : 0;
		$node  = null;
		if ( $last > 0 ) {
			$node = Tree_Model::get_node( $taxonomy, $last );
			if ( is_wp_error( $node ) ) {
				$node = null;
			}
		}

		wp_send_json_success(
			array(
				'moved' => $moved,
				'node'  => $node,
				'tree'  => $result['tree'] ?? Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	public static function add_relation(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type_id = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$to_id   = isset( $_POST['to_id'] ) ? absint( wp_unslash( $_POST['to_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$mult    = isset( $_POST['multiplicity'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['multiplicity'] ) ) : Relation::MULTIPLICITY_DEFAULT; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Relation::add( $taxonomy, $term_id, $type_id, $to_id, $mult );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$gate = self::table_validation_gate_for_node_or_table( $taxonomy, $term_id );
		if ( is_wp_error( $gate ) ) {
			Relation::remove( $taxonomy, $term_id, $type_id, $to_id );
			self::send_error( $gate );
		}

		self::send_relation_node_response( $taxonomy, $term_id );
	}

	public static function remove_relation(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type_id = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$to_id   = isset( $_POST['to_id'] ) ? absint( wp_unslash( $_POST['to_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$edge_id = isset( $_POST['edge_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['edge_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$snapshot_mult = Relation::MULTIPLICITY_DEFAULT;
		foreach ( Relation::list_outgoing( $taxonomy, $term_id ) as $edge ) {
			$match_id = '' !== $edge_id && sanitize_key( (string) ( $edge['id'] ?? '' ) ) === $edge_id;
			$match_to = $type_id > 0 && $to_id > 0
				&& (int) ( $edge['typeId'] ?? 0 ) === $type_id
				&& (int) ( $edge['toId'] ?? 0 ) === $to_id;
			if ( $match_id || $match_to ) {
				$type_id       = (int) ( $edge['typeId'] ?? $type_id );
				$to_id         = (int) ( $edge['toId'] ?? $to_id );
				$snapshot_mult = (string) ( $edge['multiplicity'] ?? Relation::MULTIPLICITY_DEFAULT );
				break;
			}
		}

		$result = Relation::remove( $taxonomy, $term_id, $type_id, $to_id, $edge_id );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$gate = self::table_validation_gate_for_node_or_table( $taxonomy, $term_id );
		if ( is_wp_error( $gate ) && $type_id > 0 && $to_id > 0 ) {
			Relation::add( $taxonomy, $term_id, $type_id, $to_id, $snapshot_mult );
			self::send_error( $gate );
		}

		self::send_relation_node_response( $taxonomy, $term_id );
	}

	public static function duplicate_relation(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$edge_id = isset( $_POST['edge_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['edge_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Relation::duplicate( $taxonomy, $term_id, $edge_id );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $term_id );
	}

	public static function move_relation(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$edge_id = isset( $_POST['edge_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['edge_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$delta   = isset( $_POST['delta'] ) ? (int) wp_unslash( $_POST['delta'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $delta > 0 ) {
			$delta = 1;
		} elseif ( $delta < 0 ) {
			$delta = -1;
		}

		$result = Relation::move( $taxonomy, $term_id, $edge_id, $delta );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $term_id );
	}

	public static function update_relation_type(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$edge_id = isset( $_POST['edge_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['edge_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type_id = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Relation::update_type( $taxonomy, $term_id, $edge_id, $type_id );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $term_id );
	}

	public static function update_relation_multiplicity(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$edge_id = isset( $_POST['edge_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['edge_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$mult    = isset( $_POST['multiplicity'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['multiplicity'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Relation::update_multiplicity( $taxonomy, $term_id, $edge_id, $mult );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $term_id );
	}

	/**
	 * Change Relation To target (not child_of — that stays reparent).
	 */
	public static function update_relation_to(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id  = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$to_id    = isset( $_POST['to_id'] ) ? absint( wp_unslash( $_POST['to_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$edge_id  = isset( $_POST['edge_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['edge_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type_id  = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type_key = isset( $_POST['type_key'] ) ? sanitize_key( wp_unslash( (string) $_POST['type_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$old_to = 0;
		if ( '' !== $edge_id ) {
			foreach ( Relation::list_outgoing( $taxonomy, $term_id ) as $edge ) {
				if ( sanitize_key( (string) ( $edge['id'] ?? '' ) ) === $edge_id ) {
					$old_to  = (int) ( $edge['toId'] ?? 0 );
					$type_id = $type_id > 0 ? $type_id : (int) ( $edge['typeId'] ?? 0 );
					break;
				}
			}
		} elseif ( Relation::TYPE_HAS_TYPE === $type_key ) {
			$old_to = Node_Type::get_type_id( $term_id );
		} elseif ( Relation::TYPE_REF_SCOPE === $type_key ) {
			$old_to = Node_Type::get_ref_scope_id( $term_id );
		} elseif ( $type_id > 0 ) {
			$type_term = get_term( $type_id, $taxonomy );
			if ( $type_term instanceof \WP_Term && Relation::is_has_type_name( $type_term->name ) ) {
				$old_to = Node_Type::get_type_id( $term_id );
			}
		}

		$result = Relation::update_to( $taxonomy, $term_id, $to_id, $edge_id, $type_id, $type_key );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$gate = self::table_validation_gate_for_node_or_table( $taxonomy, $term_id );
		if ( is_wp_error( $gate ) && $old_to > 0 ) {
			Relation::update_to( $taxonomy, $term_id, $old_to, $edge_id, $type_id, $type_key );
			self::send_error( $gate );
		}

		self::send_relation_node_response( $taxonomy, $term_id );
	}

	/**
	 * Shared success payload after relation mutations.
	 */
	private static function send_relation_node_response( string $taxonomy, int $term_id ): void {
		$node = Tree_Model::get_node( $taxonomy, $term_id );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success(
			array(
				'node' => $node,
				'tree' => Tree_Model::get_tree( $taxonomy ),
			)
		);
	}

	public static function add_attribute(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$host_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type_id = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$mult    = isset( $_POST['multiplicity'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['multiplicity'] ) )
			: Attribute::DEFAULT_MULTIPLICITY; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$binding = isset( $_POST['binding'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['binding'] ) )
			: Attribute::DEFAULT_BINDING; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Attribute::add( $taxonomy, $host_id, $name, $type_id, $mult, $binding );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $host_id );
	}

	public static function update_attribute(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$host_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$attr_id = isset( $_POST['attr_id'] ) ? absint( wp_unslash( $_POST['attr_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$changes = array();
		if ( isset( $_POST['name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$changes['name'] = sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( isset( $_POST['type_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$changes['typeId'] = absint( wp_unslash( $_POST['type_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( isset( $_POST['multiplicity'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$changes['multiplicity'] = sanitize_text_field( wp_unslash( (string) $_POST['multiplicity'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}

		$result = Attribute::update( $taxonomy, $host_id, $attr_id, $changes );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $host_id );
	}

	public static function set_attribute_type(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$host_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$attr_id = isset( $_POST['attr_id'] ) ? absint( wp_unslash( $_POST['attr_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$type_id = isset( $_POST['type_id'] ) ? absint( wp_unslash( $_POST['type_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Attribute::set_type( $taxonomy, $host_id, $attr_id, $type_id );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $host_id );
	}

	public static function set_attribute_multiplicity(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$host_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$attr_id = isset( $_POST['attr_id'] ) ? absint( wp_unslash( $_POST['attr_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$mult    = isset( $_POST['multiplicity'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['multiplicity'] ) )
			: Attribute::DEFAULT_MULTIPLICITY; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Attribute::set_multiplicity( $taxonomy, $host_id, $attr_id, $mult );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $host_id );
	}

	public static function set_attribute_binding(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$host_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$attr_id = isset( $_POST['attr_id'] ) ? absint( wp_unslash( $_POST['attr_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$binding = isset( $_POST['binding'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['binding'] ) )
			: Attribute::DEFAULT_BINDING; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Attribute::set_binding( $taxonomy, $host_id, $attr_id, $binding );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $host_id );
	}

	public static function set_attribute_fixed(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$host_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$attr_id = isset( $_POST['attr_id'] ) ? absint( wp_unslash( $_POST['attr_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$values = null;
		if ( isset( $_POST['clear'] ) && '1' === (string) wp_unslash( $_POST['clear'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$values = array();
		} elseif ( isset( $_POST['values'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = wp_unslash( $_POST['values'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$values = array_map( 'sanitize_text_field', $decoded );
				} else {
					$values = array( sanitize_text_field( $raw ) );
				}
			} elseif ( is_array( $raw ) ) {
				$values = array_map( 'sanitize_text_field', $raw );
			}
		}

		$result = Attribute::set_fixed_values( $taxonomy, $host_id, $attr_id, $values );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $host_id );
	}

	/**
	 * Save closed Festwerte (option leaves) on a concrete enum node.
	 */
	public static function set_enum_values(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$values = array();
		if ( isset( $_POST['values'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = wp_unslash( $_POST['values'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				if ( is_array( $decoded ) ) {
					$values = $decoded;
				} elseif ( '' !== trim( $raw ) ) {
					$values = array( $raw );
				}
			} elseif ( is_array( $raw ) ) {
				$values = $raw;
			}
		}

		$result = Node_Type::set_enum_values( $taxonomy, $term_id, $values );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $term_id );
	}

	public static function remove_attribute(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$host_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$attr_id = isset( $_POST['attr_id'] ) ? absint( wp_unslash( $_POST['attr_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Attribute::remove( $taxonomy, $host_id, $attr_id );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $host_id );
	}

	public static function reorder_attribute(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$host_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$attr_id = isset( $_POST['attr_id'] ) ? absint( wp_unslash( $_POST['attr_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$delta   = isset( $_POST['delta'] ) ? (int) wp_unslash( $_POST['delta'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Attribute::reorder( $taxonomy, $host_id, $attr_id, $delta );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $host_id );
	}

	public static function move_attribute_to_parent(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$host_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$attr_id = isset( $_POST['attr_id'] ) ? absint( wp_unslash( $_POST['attr_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Attribute::move_to_parent( $taxonomy, $host_id, $attr_id );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $host_id );
	}

	public static function move_attribute_to_child(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$host_id  = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$attr_id  = isset( $_POST['attr_id'] ) ? absint( wp_unslash( $_POST['attr_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$child_id = isset( $_POST['child_id'] ) ? absint( wp_unslash( $_POST['child_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Attribute::move_to_child( $taxonomy, $host_id, $attr_id, $child_id );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $host_id );
	}

	public static function set_attribute_hidden(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$host_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$attr_id = isset( $_POST['attr_id'] ) ? absint( wp_unslash( $_POST['attr_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$hidden  = isset( $_POST['hidden'] ) && '1' === (string) wp_unslash( $_POST['hidden'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Attribute::set_hidden( $taxonomy, $host_id, $attr_id, $hidden );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $host_id );
	}

	public static function set_attribute_type_extras(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$host_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$attr_id = isset( $_POST['attr_id'] ) ? absint( wp_unslash( $_POST['attr_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$extras = array();
		if ( isset( $_POST['extras'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = wp_unslash( $_POST['extras'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				$extras  = is_array( $decoded ) ? $decoded : array();
			} elseif ( is_array( $raw ) ) {
				$extras = $raw;
			}
		}

		$result = Attribute::set_type_extras( $taxonomy, $host_id, $attr_id, $extras );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $host_id );
	}

	public static function duplicate_attribute(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$host_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$attr_id = isset( $_POST['attr_id'] ) ? absint( wp_unslash( $_POST['attr_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Attribute::duplicate( $taxonomy, $host_id, $attr_id );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $host_id );
	}

	public static function set_attribute_readonly(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}
		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$host_id  = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$attr_id  = isset( $_POST['attr_id'] ) ? absint( wp_unslash( $_POST['attr_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$readonly = isset( $_POST['readonly'] ) && '1' === (string) wp_unslash( $_POST['readonly'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$result = Attribute::set_readonly( $taxonomy, $host_id, $attr_id, $readonly );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		self::send_relation_node_response( $taxonomy, $host_id );
	}

	/**
	 * Apply a table rule fix (create_zeile / create_zeile_field / create_fields).
	 */
	public static function fix_table_band_fields(): void {
		self::verify_request();
		$taxonomy = self::request_taxonomy();
		if ( is_wp_error( $taxonomy ) ) {
			self::send_error( $taxonomy );
		}

		if ( ! current_user_can( Capabilities::edit_terms( $taxonomy ) ) ) {
			self::send_error( new \WP_Error( 'wtt_forbidden', __( 'Forbidden.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}

		$table_id = isset( $_POST['term_id'] ) ? absint( wp_unslash( $_POST['term_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$band_key = isset( $_POST['band'] ) ? sanitize_key( wp_unslash( $_POST['band'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$action   = isset( $_POST['fix_action'] ) ? sanitize_key( wp_unslash( $_POST['fix_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === $action ) {
			$action = 'create_fields';
		}

		$result = Table_Validator::apply_fix( $taxonomy, $table_id, $action, $band_key );
		if ( is_wp_error( $result ) ) {
			self::send_error( $result );
		}

		$node = Tree_Model::get_node( $taxonomy, $table_id );
		if ( is_wp_error( $node ) ) {
			self::send_error( $node );
		}

		wp_send_json_success(
			array(
				'node'       => $node,
				'tree'       => Tree_Model::get_tree( $taxonomy ),
				'created'    => $result['created'] ?? array(),
				'validation' => $result['validation'] ?? null,
			)
		);
	}

	/**
	 * Block save when a table definition has illegal Kopf/Fuss cardinality.
	 *
	 * @return true|\WP_Error
	 */
	private static function table_validation_gate( string $taxonomy, int $term_id ) {
		if ( $term_id <= 0 ) {
			return true;
		}
		$is_table = Node_Type::has_type_named( $taxonomy, $term_id, 'table' )
			|| Node_Type::is_table_type_catalog( $taxonomy, $term_id );
		if ( ! $is_table ) {
			return true;
		}
		$validation = Table_Validator::validate( $taxonomy, $term_id );
		if ( ! empty( $validation['blocking'] ) ) {
			$message = ! empty( $validation['errors'] )
				? implode( ' ', $validation['errors'] )
				: __( 'Table definition is invalid.', 'wp-taxonomy-tree' );
			return new \WP_Error(
				'wtt_table_invalid',
				$message,
				array(
					'status'          => 400,
					'tableValidation' => $validation,
				)
			);
		}
		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function table_validation_gate_for_node_or_table( string $taxonomy, int $term_id ) {
		$gate = self::table_validation_gate( $taxonomy, $term_id );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}
		$parent_table = self::nearest_table_ancestor( $taxonomy, $term_id );
		if ( $parent_table > 0 && $parent_table !== $term_id ) {
			return self::table_validation_gate( $taxonomy, $parent_table );
		}
		return true;
	}

	/**
	 * Walk term parents for a table-typed node (for band/field relation edits).
	 */
	private static function nearest_table_ancestor( string $taxonomy, int $term_id ): int {
		$id    = $term_id;
		$guard = 0;
		while ( $id > 0 && $guard < 32 ) {
			if ( Node_Type::has_type_named( $taxonomy, $id, 'table' )
				|| Node_Type::is_table_type_catalog( $taxonomy, $id ) ) {
				return $id;
			}
			$term = get_term( $id, $taxonomy );
			if ( ! $term instanceof \WP_Term ) {
				break;
			}
			$id = (int) $term->parent;
			++$guard;
		}
		return 0;
	}

	private static function verify_request(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			self::send_error( new \WP_Error( 'wtt_bad_nonce', __( 'Invalid nonce.', 'wp-taxonomy-tree' ), array( 'status' => 403 ) ) );
		}
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function request_taxonomy() {
		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! Taxonomy::is_scaffold( $taxonomy ) || ! Tree_Model::is_hierarchical_taxonomy( $taxonomy ) ) {
			return new \WP_Error( 'wtt_bad_taxonomy', __( 'Not a hierarchical taxonomy.', 'wp-taxonomy-tree' ) );
		}

		return $taxonomy;
	}

	private static function send_error( \WP_Error $error ): void {
		$data   = $error->get_error_data();
		$status = ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 400;
		wp_send_json_error(
			array(
				'code'    => $error->get_error_code(),
				'message' => self::plain_error_message( $error ),
			),
			$status
		);
	}

	/**
	 * WP core/locale strings often embed HTML entities (e.g. &#8222;); decode for UI text.
	 */
	private static function plain_error_message( \WP_Error $error ): string {
		$raw = $error->get_error_message();
		$raw = html_entity_decode( $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return wp_strip_all_tags( $raw );
	}
}
